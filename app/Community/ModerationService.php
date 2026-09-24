<?php

declare(strict_types=1);

namespace MeldeVerkehr\Community;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationException;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class ModerationService
{
    private const TARGETS = ['POST','COMMENT','PROBLEM_AREA','PROFILE','MESSAGE'];
    private const ACTIONS = ['DISMISS','HIDE','WARN','RESTRICT','APPROVE'];
    private const APPEAL_OUTCOMES = ['UPHOLD','OVERTURN','PARTIAL'];
    private const ESCALATION_SOURCES = ['REPORT','APPEAL','ABUSE_FLAG'];
    private const ESCALATION_PRIORITIES = ['NORMAL','HIGH','URGENT'];
    private const ABUSE_ACTIONS = ['DISMISS','WARN','RESTRICT_REPORTING','RESTRICT_COMMUNITY'];

    private readonly CommunityAbuseService $abuse;

    public function __construct(
        private readonly PDO $pdo,
        private readonly PermissionService $permissions,
        ?CommunityAbuseService $abuse = null,
        private readonly ?AuditLogger $audit = null
    ) {
        $this->abuse = $abuse ?? new CommunityAbuseService($pdo);
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

        $targetOwner = $this->targetOwner($targetType, $targetId);
        if ($targetOwner === $reporterUserId) {
            throw new \DomainException('Eigene Inhalte können nicht über das Meldesystem gemeldet werden.');
        }

        $category = strtoupper(trim($category));
        if ($category === '' || strlen($category) > 50) {
            throw new \InvalidArgumentException('Meldekategorie ist ungültig.');
        }

        $reason = trim((string) $reason);
        if (mb_strlen($reason) > 1000) {
            throw new \InvalidArgumentException('Meldegrund ist zu lang.');
        }

        $duplicate = $this->pdo->prepare(
            'SELECT id FROM community_reports
             WHERE reporter_user_id = :reporter
               AND target_type = :target_type
               AND target_id = :target_id
               AND (
                    status IN ("OPEN","IN_REVIEW")
                    OR created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
               )
             ORDER BY created_at DESC LIMIT 1'
        );
        $duplicate->execute([
            'reporter' => $reporterUserId,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);
        if ($duplicate->fetchColumn() !== false) {
            throw new \DomainException('Dieser Inhalt wurde von dir bereits kürzlich gemeldet.');
        }

        $this->abuse->assertAllowed($reporterUserId, 'REPORT', $reason);

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

        $this->abuse->record(
            $reporterUserId,
            'REPORT',
            $targetType,
            $targetId,
            $reason,
            ['category' => $category, 'report_id' => $id]
        );
        $risk = $this->abuse->attachReportRisk($id, $reporterUserId);

        $this->audit?->log('COMMUNITY_REPORT_CREATED', 'community_report', $id, 'USER', $reporterUserId, [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'risk_score' => $risk['score'],
        ]);

        return $id;
    }

    public function queue(string $moderatorUserId): array
    {
        $this->assertModerator($moderatorUserId);

        $stmt = $this->pdo->query(
            'SELECT cr.*, cp.username AS reporter_username,
                    COALESCE(rr.risk_score, 0) AS reporter_risk_score,
                    rr.indicators_json AS reporter_risk_indicators
             FROM community_reports cr
             LEFT JOIN community_profiles cp ON cp.user_id = cr.reporter_user_id
             LEFT JOIN community_report_risk rr ON rr.report_id = cr.id
             WHERE cr.status IN ("OPEN","IN_REVIEW")
             ORDER BY COALESCE(rr.risk_score, 0) DESC, cr.created_at'
        );

        $rows = array_values(array_filter(
            $stmt->fetchAll(),
            fn(array $report): bool => $this->inModeratorScope(
                $moderatorUserId,
                (string) $report['target_type'],
                (string) $report['target_id']
            )
        ));

        foreach ($rows as &$row) {
            $row['reporter_risk_score'] = (int) ($row['reporter_risk_score'] ?? 0);
            $row['reporter_risk_indicators'] = $this->decodeJson($row['reporter_risk_indicators'] ?? null);
        }
        unset($row);

        return $rows;
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

        $report = $this->reportForReview($moderatorUserId, $reportId);
        $reason = trim((string) $reason);
        if (mb_strlen($reason) > 1000) {
            throw new \InvalidArgumentException('Moderationsbegründung ist zu lang.');
        }

        $beforeState = $this->targetState((string) $report['target_type'], (string) $report['target_id']);

        $this->pdo->beginTransaction();
        try {
            if ($action === 'HIDE') {
                $this->hideTarget((string) $report['target_type'], (string) $report['target_id']);
            } elseif ($action === 'APPROVE' && (string) $report['target_type'] === 'PROBLEM_AREA') {
                $this->approveProblemTarget((string) $report['target_id']);
            } elseif ($action === 'RESTRICT') {
                $owner = $this->targetOwner((string) $report['target_type'], (string) $report['target_id']);
                if ($owner === null) {
                    throw new \DomainException('Zielkonto der Einschränkung konnte nicht bestimmt werden.');
                }
                $this->abuse->restrict(
                    $owner,
                    'COMMUNITY',
                    168,
                    $reason !== '' ? $reason : 'Moderationsmaßnahme',
                    $moderatorUserId
                );
            }

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

            $actionId = Uuid::v4();
            $this->pdo->prepare(
                'INSERT INTO community_moderation_actions
                 (id, report_id, moderator_user_id, action_type, target_type, target_id, reason, created_at)
                 VALUES
                 (:id, :report_id, :moderator, :action_type, :target_type, :target_id, :reason, UTC_TIMESTAMP())'
            )->execute([
                'id' => $actionId,
                'report_id' => $reportId,
                'moderator' => $moderatorUserId,
                'action_type' => $action,
                'target_type' => $report['target_type'],
                'target_id' => $report['target_id'],
                'reason' => $reason === '' ? null : $reason,
            ]);

            $afterState = $this->targetState((string) $report['target_type'], (string) $report['target_id']);
            $this->pdo->prepare(
                'INSERT INTO community_moderation_action_snapshots
                 (action_id, before_state_json, after_state_json, created_at)
                 VALUES (:action_id, :before_state, :after_state, UTC_TIMESTAMP())'
            )->execute([
                'action_id' => $actionId,
                'before_state' => $this->encodeJson($beforeState),
                'after_state' => $this->encodeJson($afterState),
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit?->log('COMMUNITY_MODERATION_RESOLVED', 'community_report', $reportId, 'USER', $moderatorUserId, [
            'action' => $action,
            'target_type' => $report['target_type'],
            'target_id' => $report['target_id'],
        ]);
    }

    public function appealableForUser(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cr.*, ma.id AS action_id, ma.action_type, ma.reason AS action_reason,
                    a.id AS appeal_id, a.status AS appeal_status, a.reason AS appeal_reason,
                    a.resolution AS appeal_resolution, a.created_at AS appeal_created_at,
                    a.resolved_at AS appeal_resolved_at
             FROM community_reports cr
             INNER JOIN community_moderation_actions ma ON ma.report_id = cr.id
             LEFT JOIN community_moderation_appeals a
                ON a.report_id = cr.id AND a.appellant_user_id = :user_id
             WHERE cr.status = "RESOLVED"
               AND ma.action_type IN ("HIDE","WARN","RESTRICT")
             ORDER BY ma.created_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        $seen = [];
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            if (isset($seen[$row['id']])) {
                continue;
            }
            $seen[$row['id']] = true;

            if ($this->targetOwner((string) $row['target_type'], (string) $row['target_id']) !== $userId) {
                continue;
            }

            $items[] = $row;
        }

        return $items;
    }

    public function submitAppeal(string $userId, string $reportId, string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 4000) {
            throw new \InvalidArgumentException('Der Einspruch muss zwischen 10 und 4.000 Zeichen lang sein.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT cr.*, ma.action_type
             FROM community_reports cr
             INNER JOIN community_moderation_actions ma ON ma.report_id = cr.id
             WHERE cr.id = :id AND cr.status = "RESOLVED"
               AND ma.action_type IN ("HIDE","WARN","RESTRICT")
             ORDER BY ma.created_at DESC LIMIT 1'
        );
        $stmt->execute(['id' => $reportId]);
        $report = $stmt->fetch();

        if (!is_array($report)) {
            throw new \DomainException('Anfechtbare Moderationsentscheidung nicht gefunden.');
        }

        if ($this->targetOwner((string) $report['target_type'], (string) $report['target_id']) !== $userId) {
            throw new AuthorizationException('Access denied.');
        }

        $id = Uuid::v4();
        try {
            $this->pdo->prepare(
                'INSERT INTO community_moderation_appeals
                 (id, report_id, appellant_user_id, reason, status, assigned_user_id,
                  resolution, created_at, resolved_at)
                 VALUES
                 (:id, :report_id, :user_id, :reason, "OPEN", NULL, NULL, UTC_TIMESTAMP(), NULL)'
            )->execute([
                'id' => $id,
                'report_id' => $reportId,
                'user_id' => $userId,
                'reason' => $reason,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new \DomainException('Zu dieser Entscheidung wurde bereits ein Einspruch eingereicht.');
            }
            throw $e;
        }

        $this->audit?->log('COMMUNITY_MODERATION_APPEAL_CREATED', 'community_moderation_appeal', $id, 'USER', $userId, [
            'report_id' => $reportId,
        ]);

        return $id;
    }

    public function appealsQueue(string $moderatorUserId): array
    {
        $this->assertModerator($moderatorUserId);

        $stmt = $this->pdo->query(
            'SELECT a.*, cr.target_type, cr.target_id, cr.category, cr.resolution AS report_resolution,
                    cp.username AS appellant_username
             FROM community_moderation_appeals a
             INNER JOIN community_reports cr ON cr.id = a.report_id
             LEFT JOIN community_profiles cp ON cp.user_id = a.appellant_user_id
             WHERE a.status IN ("OPEN","IN_REVIEW")
             ORDER BY a.created_at'
        );

        return array_values(array_filter(
            $stmt->fetchAll(),
            fn(array $appeal): bool => $this->inModeratorScope(
                $moderatorUserId,
                (string) $appeal['target_type'],
                (string) $appeal['target_id']
            )
        ));
    }

    public function resolveAppeal(
        string $moderatorUserId,
        string $appealId,
        string $outcome,
        string $reason
    ): void {
        $this->assertModerator($moderatorUserId);
        $outcome = strtoupper(trim($outcome));
        if (!in_array($outcome, self::APPEAL_OUTCOMES, true)) {
            throw new \InvalidArgumentException('Ungültiges Einspruchsergebnis.');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 4000) {
            throw new \InvalidArgumentException('Eine nachvollziehbare Einspruchsbegründung ist erforderlich.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.*, cr.target_type, cr.target_id
             FROM community_moderation_appeals a
             INNER JOIN community_reports cr ON cr.id = a.report_id
             WHERE a.id = :id AND a.status IN ("OPEN","IN_REVIEW") LIMIT 1'
        );
        $stmt->execute(['id' => $appealId]);
        $appeal = $stmt->fetch();

        if (!is_array($appeal)) {
            throw new \DomainException('Offener Einspruch nicht gefunden.');
        }

        if (!$this->inModeratorScope(
            $moderatorUserId,
            (string) $appeal['target_type'],
            (string) $appeal['target_id']
        )) {
            throw new AuthorizationException('Access denied.');
        }

        $this->pdo->beginTransaction();
        try {
            if ($outcome === 'OVERTURN') {
                $this->overturnLatestAction(
                    (string) $appeal['report_id'],
                    (string) $appeal['target_type'],
                    (string) $appeal['target_id'],
                    $moderatorUserId
                );
            } elseif ($outcome === 'PARTIAL') {
                $this->partiallyRelaxLatestAction(
                    (string) $appeal['report_id'],
                    (string) $appeal['target_type'],
                    (string) $appeal['target_id'],
                    $moderatorUserId
                );
            }

            $status = match ($outcome) {
                'UPHOLD' => 'UPHELD',
                'OVERTURN' => 'OVERTURNED',
                'PARTIAL' => 'PARTIAL',
            };

            $this->pdo->prepare(
                'UPDATE community_moderation_appeals
                 SET status = :status, assigned_user_id = :moderator,
                     resolution = :resolution, resolved_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            )->execute([
                'status' => $status,
                'moderator' => $moderatorUserId,
                'resolution' => $reason,
                'id' => $appealId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit?->log('COMMUNITY_MODERATION_APPEAL_RESOLVED', 'community_moderation_appeal', $appealId, 'USER', $moderatorUserId, [
            'outcome' => $outcome,
            'report_id' => $appeal['report_id'],
        ]);
    }

    public function abuseFlags(string $moderatorUserId): array
    {
        $this->assertModerator($moderatorUserId);

        $stmt = $this->pdo->query(
            'SELECT af.*, cp.username
             FROM community_abuse_flags af
             LEFT JOIN community_profiles cp ON cp.user_id = af.user_id
             WHERE af.status IN ("OPEN","IN_REVIEW")
             ORDER BY FIELD(af.severity, "CRITICAL","HIGH","MEDIUM","LOW"), af.risk_score DESC, af.created_at'
        );

        $rows = array_values(array_filter(
            $stmt->fetchAll(),
            fn(array $flag): bool => $this->inModeratorScope(
                $moderatorUserId,
                'PROFILE',
                (string) $flag['user_id']
            )
        ));

        foreach ($rows as &$row) {
            $row['evidence'] = $this->decodeJson($row['evidence_json'] ?? null);
        }
        unset($row);

        return $rows;
    }

    public function resolveAbuseFlag(
        string $moderatorUserId,
        string $flagId,
        string $action,
        string $reason
    ): void {
        $this->assertModerator($moderatorUserId);
        $action = strtoupper(trim($action));
        if (!in_array($action, self::ABUSE_ACTIONS, true)) {
            throw new \InvalidArgumentException('Ungültige Abuse-Entscheidung.');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
            throw new \InvalidArgumentException('Eine nachvollziehbare Begründung ist erforderlich.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM community_abuse_flags
             WHERE id = :id AND status IN ("OPEN","IN_REVIEW") LIMIT 1'
        );
        $stmt->execute(['id' => $flagId]);
        $flag = $stmt->fetch();

        if (!is_array($flag)) {
            throw new \DomainException('Abuse-Flag nicht gefunden.');
        }

        if (!$this->inModeratorScope($moderatorUserId, 'PROFILE', (string) $flag['user_id'])) {
            throw new AuthorizationException('Access denied.');
        }

        if ($action === 'RESTRICT_REPORTING') {
            $this->abuse->restrict(
                (string) $flag['user_id'],
                'REPORTING',
                24,
                $reason,
                $moderatorUserId
            );
        } elseif ($action === 'RESTRICT_COMMUNITY') {
            $this->abuse->restrict(
                (string) $flag['user_id'],
                'COMMUNITY',
                168,
                $reason,
                $moderatorUserId
            );
        }

        $this->pdo->prepare(
            'UPDATE community_abuse_flags
             SET status = "RESOLVED", reviewed_by = :moderator, reviewed_at = UTC_TIMESTAMP(),
                 resolution = :resolution
             WHERE id = :id'
        )->execute([
            'moderator' => $moderatorUserId,
            'resolution' => $action . ': ' . $reason,
            'id' => $flagId,
        ]);

        $this->audit?->log('COMMUNITY_ABUSE_FLAG_RESOLVED', 'community_abuse_flag', $flagId, 'USER', $moderatorUserId, [
            'action' => $action,
            'user_id' => $flag['user_id'],
        ]);
    }

    public function escalate(
        string $moderatorUserId,
        string $sourceType,
        string $sourceId,
        string $priority,
        string $reason
    ): string {
        $this->assertModerator($moderatorUserId);

        $sourceType = strtoupper(trim($sourceType));
        $priority = strtoupper(trim($priority));
        $reason = trim($reason);

        if (!in_array($sourceType, self::ESCALATION_SOURCES, true)) {
            throw new \InvalidArgumentException('Ungültige Eskalationsquelle.');
        }
        if (!in_array($priority, self::ESCALATION_PRIORITIES, true)) {
            throw new \InvalidArgumentException('Ungültige Eskalationspriorität.');
        }
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 4000) {
            throw new \InvalidArgumentException('Eskalationsbegründung ist erforderlich.');
        }

        $scope = $this->escalationScope($sourceType, $sourceId);
        if ($scope === null) {
            throw new \DomainException('Eskalationsquelle wurde nicht gefunden.');
        }
        if (!$this->inModeratorScope($moderatorUserId, $scope['target_type'], $scope['target_id'])) {
            throw new AuthorizationException('Access denied.');
        }

        $existing = $this->pdo->prepare(
            'SELECT id FROM community_moderation_escalations
             WHERE source_type = :source_type AND source_id = :source_id
               AND status IN ("OPEN","IN_REVIEW") LIMIT 1'
        );
        $existing->execute(['source_type' => $sourceType, 'source_id' => $sourceId]);
        $existingId = $existing->fetchColumn();
        if (is_string($existingId) && $existingId !== '') {
            return $existingId;
        }

        $id = Uuid::v4();
        $this->pdo->prepare(
            'INSERT INTO community_moderation_escalations
             (id, source_type, source_id, created_by_user_id, priority, reason, status,
              assigned_user_id, resolution, created_at, resolved_at)
             VALUES
             (:id, :source_type, :source_id, :created_by, :priority, :reason, "OPEN",
              NULL, NULL, UTC_TIMESTAMP(), NULL)'
        )->execute([
            'id' => $id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_by' => $moderatorUserId,
            'priority' => $priority,
            'reason' => $reason,
        ]);

        $this->audit?->log('COMMUNITY_MODERATION_ESCALATED', 'community_moderation_escalation', $id, 'USER', $moderatorUserId, [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'priority' => $priority,
        ]);

        return $id;
    }

    public function escalations(string $moderatorUserId): array
    {
        $this->assertModerator($moderatorUserId);

        $senior = $this->isSeniorModerator($moderatorUserId);
        $sql =
            'SELECT e.*, cp.username AS creator_username
             FROM community_moderation_escalations e
             LEFT JOIN community_profiles cp ON cp.user_id = e.created_by_user_id
             WHERE e.status IN ("OPEN","IN_REVIEW")';

        $params = [];
        if (!$senior) {
            $sql .= ' AND e.created_by_user_id = :user_id';
            $params['user_id'] = $moderatorUserId;
        }

        $sql .= ' ORDER BY FIELD(e.priority, "URGENT","HIGH","NORMAL"), e.created_at';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function resolveEscalation(
        string $moderatorUserId,
        string $escalationId,
        string $resolution
    ): void {
        $this->assertSeniorModerator($moderatorUserId);

        $resolution = trim($resolution);
        if (mb_strlen($resolution) < 5 || mb_strlen($resolution) > 4000) {
            throw new \InvalidArgumentException('Eskalationsentscheidung ist erforderlich.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE community_moderation_escalations
             SET status = "RESOLVED", assigned_user_id = :user_id,
                 resolution = :resolution, resolved_at = UTC_TIMESTAMP()
             WHERE id = :id AND status IN ("OPEN","IN_REVIEW")'
        );
        $stmt->execute([
            'user_id' => $moderatorUserId,
            'resolution' => $resolution,
            'id' => $escalationId,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Offene Eskalation nicht gefunden.');
        }

        $this->audit?->log('COMMUNITY_MODERATION_ESCALATION_RESOLVED', 'community_moderation_escalation', $escalationId, 'USER', $moderatorUserId);
    }

    public function pendingProblemAreas(string $moderatorUserId): array
    {
        $this->assertModerator($moderatorUserId);

        $stmt = $this->pdo->query(
            'SELECT ppa.*, cp.username
             FROM public_problem_areas ppa
             LEFT JOIN community_profiles cp ON cp.user_id = ppa.created_by_user_id
             WHERE ppa.moderation_status = "PENDING"
             ORDER BY ppa.created_at'
        );

        return array_values(array_filter(
            $stmt->fetchAll(),
            fn(array $area): bool => $this->inModeratorScope(
                $moderatorUserId,
                'PROBLEM_AREA',
                (string) $area['id']
            )
        ));
    }

    public function approveProblemArea(string $moderatorUserId, string $areaId): void
    {
        $this->assertModerator($moderatorUserId);

        if (!$this->inModeratorScope($moderatorUserId, 'PROBLEM_AREA', $areaId)) {
            throw new AuthorizationException('Access denied.');
        }

        $before = $this->targetState('PROBLEM_AREA', $areaId);
        $this->approveProblemTarget($areaId);
        $after = $this->targetState('PROBLEM_AREA', $areaId);

        $actionId = Uuid::v4();
        $this->pdo->prepare(
            'INSERT INTO community_moderation_actions
             (id, report_id, moderator_user_id, action_type, target_type, target_id, reason, created_at)
             VALUES
             (:id, NULL, :moderator, "APPROVE", "PROBLEM_AREA", :target_id,
              "Initial public problem area approval", UTC_TIMESTAMP())'
        )->execute([
            'id' => $actionId,
            'moderator' => $moderatorUserId,
            'target_id' => $areaId,
        ]);

        $this->pdo->prepare(
            'INSERT INTO community_moderation_action_snapshots
             (action_id, before_state_json, after_state_json, created_at)
             VALUES (:action_id, :before_state, :after_state, UTC_TIMESTAMP())'
        )->execute([
            'action_id' => $actionId,
            'before_state' => $this->encodeJson($before),
            'after_state' => $this->encodeJson($after),
        ]);
    }

    private function reportForReview(string $moderatorUserId, string $reportId): array
    {
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
            throw new AuthorizationException('Access denied.');
        }

        return $report;
    }

    private function overturnLatestAction(
        string $reportId,
        string $targetType,
        string $targetId,
        string $moderatorUserId
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT ma.action_type, mas.before_state_json
             FROM community_moderation_actions ma
             LEFT JOIN community_moderation_action_snapshots mas ON mas.action_id = ma.id
             WHERE ma.report_id = :report_id
             ORDER BY ma.created_at DESC LIMIT 1'
        );
        $stmt->execute(['report_id' => $reportId]);
        $action = $stmt->fetch();

        if (!is_array($action)) {
            throw new \DomainException('Ursprüngliche Moderationsaktion wurde nicht gefunden.');
        }

        if ((string) $action['action_type'] === 'RESTRICT') {
            $owner = $this->targetOwner($targetType, $targetId);
            if ($owner !== null) {
                $this->abuse->clearRestriction($owner, $moderatorUserId);
            }
            return;
        }

        if ((string) $action['action_type'] === 'HIDE') {
            $before = $this->decodeJson($action['before_state_json'] ?? null);
            if ($before === []) {
                throw new \DomainException('Vorher-Zustand der Moderationsaktion ist nicht verfügbar.');
            }
            $this->restoreTargetState($targetType, $targetId, $before);
        }
    }

    private function partiallyRelaxLatestAction(
        string $reportId,
        string $targetType,
        string $targetId,
        string $moderatorUserId
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT action_type FROM community_moderation_actions
             WHERE report_id = :report_id ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute(['report_id' => $reportId]);
        $action = $stmt->fetchColumn();

        if ($action === 'RESTRICT') {
            $owner = $this->targetOwner($targetType, $targetId);
            if ($owner !== null) {
                $this->abuse->clearRestriction($owner, $moderatorUserId);
            }
        }
    }

    private function escalationScope(string $sourceType, string $sourceId): ?array
    {
        if ($sourceType === 'REPORT') {
            $stmt = $this->pdo->prepare(
                'SELECT target_type, target_id FROM community_reports WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $sourceId]);
            $row = $stmt->fetch();

            return is_array($row) ? $row : null;
        }

        if ($sourceType === 'APPEAL') {
            $stmt = $this->pdo->prepare(
                'SELECT cr.target_type, cr.target_id
                 FROM community_moderation_appeals a
                 INNER JOIN community_reports cr ON cr.id = a.report_id
                 WHERE a.id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $sourceId]);
            $row = $stmt->fetch();

            return is_array($row) ? $row : null;
        }

        if ($sourceType === 'ABUSE_FLAG') {
            $stmt = $this->pdo->prepare(
                'SELECT user_id FROM community_abuse_flags WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $sourceId]);
            $userId = $stmt->fetchColumn();

            return is_string($userId) && $userId !== ''
                ? ['target_type' => 'PROFILE', 'target_id' => $userId]
                : null;
        }

        return null;
    }

    private function assertModerator(string $userId): void
    {
        if (
            !$this->permissions->can($userId, 'moderation.review')
            && !$this->permissions->hasRole($userId, 'SUPER_ADMIN')
        ) {
            throw new AuthorizationException('Access denied.');
        }
    }

    private function assertSeniorModerator(string $userId): void
    {
        if (!$this->isSeniorModerator($userId)) {
            throw new AuthorizationException('Access denied.');
        }
    }

    private function isSeniorModerator(string $userId): bool
    {
        return $this->permissions->can($userId, 'moderation.action')
            || $this->permissions->hasRole($userId, 'ADMIN')
            || $this->permissions->hasRole($userId, 'SUPER_ADMIN');
    }

    private function inModeratorScope(string $moderatorUserId, string $targetType, string $targetId): bool
    {
        if (
            $this->permissions->hasRole($moderatorUserId, 'SUPER_ADMIN')
            || $this->permissions->hasRole($moderatorUserId, 'ADMIN')
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
             FROM community_profiles WHERE user_id = :user_id AND status IN ("ACTIVE","RESTRICTED") LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function approveProblemTarget(string $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE public_problem_areas
             SET moderation_status = "APPROVED", updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND moderation_status IN ("PENDING","HIDDEN")'
        );
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Öffentliche Problemstelle wurde nicht gefunden oder ist bereits freigegeben.');
        }
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

    private function targetState(string $type, string $id): array
    {
        $sql = match ($type) {
            'POST' => ['SELECT status FROM community_posts WHERE id = :id', 'status'],
            'COMMENT' => ['SELECT status FROM community_comments WHERE id = :id', 'status'],
            'PROBLEM_AREA' => ['SELECT moderation_status FROM public_problem_areas WHERE id = :id', 'moderation_status'],
            'PROFILE' => ['SELECT status FROM community_profiles WHERE user_id = :id', 'status'],
            'MESSAGE' => ['SELECT status FROM community_messages WHERE id = :id', 'status'],
            default => null,
        };

        if ($sql === null) {
            return [];
        }

        [$query, $field] = $sql;
        $stmt = $this->pdo->prepare($query);
        $stmt->execute(['id' => $id]);
        $value = $stmt->fetchColumn();

        return $value === false ? [] : ['field' => $field, 'value' => (string) $value];
    }

    private function restoreTargetState(string $type, string $id, array $state): void
    {
        $field = (string) ($state['field'] ?? '');
        $value = (string) ($state['value'] ?? '');
        $expectedField = match ($type) {
            'POST', 'COMMENT', 'PROFILE', 'MESSAGE' => 'status',
            'PROBLEM_AREA' => 'moderation_status',
            default => '',
        };

        if ($field === '' || $value === '' || $field !== $expectedField) {
            throw new \DomainException('Gespeicherter Vorher-Zustand ist ungültig.');
        }

        $sql = match ($type) {
            'POST' => 'UPDATE community_posts SET status = :value, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            'COMMENT' => 'UPDATE community_comments SET status = :value, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            'PROBLEM_AREA' => 'UPDATE public_problem_areas SET moderation_status = :value, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            'PROFILE' => 'UPDATE community_profiles SET status = :value, updated_at = UTC_TIMESTAMP() WHERE user_id = :id',
            'MESSAGE' => 'UPDATE community_messages SET status = :value WHERE id = :id',
            default => null,
        };

        if ($sql === null) {
            throw new \DomainException('Moderationsziel kann nicht wiederhergestellt werden.');
        }

        $this->pdo->prepare($sql)->execute(['value' => $value, 'id' => $id]);
    }

    private function encodeJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function decodeJson(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
