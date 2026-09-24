<?php

declare(strict_types=1);

namespace MeldeVerkehr\Community;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationException;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class ReputationService
{
    private const CATEGORIES = ['COMMUNITY','REPORT_QUALITY','PROBLEM_REPORT','AUTHORITY_INFO'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?PermissionService $permissions = null,
        private readonly ?AuditLogger $audit = null
    ) {
    }

    public function award(
        string $userId,
        string $category,
        int $points,
        string $reasonKey,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $uniqueKey = null
    ): bool {
        $category = $this->category($category);

        if ($points < -100 || $points > 100 || $points === 0) {
            throw new \InvalidArgumentException('Ungültige Punktezahl.');
        }

        $reasonKey = strtoupper(trim($reasonKey));
        if ($reasonKey === '' || strlen($reasonKey) > 80) {
            throw new \InvalidArgumentException('Ungültiger Reputationsgrund.');
        }

        if ($uniqueKey !== null && $this->uniqueKeyExists($uniqueKey)) {
            return false;
        }

        $started = !$this->pdo->inTransaction();
        if ($started) {
            $this->pdo->beginTransaction();
        }

        try {
            $usage = $this->lockDailyUsage($userId, $category);
            $policy = $this->policy($category);
            $multiplier = $points > 0
                ? $this->multiplier((int) $usage['event_count'], $policy)
                : 1.0;

            $effectivePoints = $points > 0
                ? max(0, (int) round($points * $multiplier))
                : $points;

            if ($points > 0) {
                $remaining = max(
                    0,
                    (int) $policy['daily_positive_cap'] - (int) $usage['awarded_positive_points']
                );
                $effectivePoints = min($effectivePoints, $remaining);
            }

            $eventId = Uuid::v4();
            try {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO reputation_events
                     (id, user_id, category, points, reason_key, source_type, source_id, unique_key, created_at)
                     VALUES
                     (:id, :user_id, :category, :points, :reason_key, :source_type, :source_id, :unique_key, UTC_TIMESTAMP())'
                );
                $stmt->execute([
                    'id' => $eventId,
                    'user_id' => $userId,
                    'category' => $category,
                    'points' => $effectivePoints,
                    'reason_key' => $reasonKey,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'unique_key' => $uniqueKey,
                ]);
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000' && $uniqueKey !== null) {
                    if ($started && $this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    return false;
                }
                throw $e;
            }

            $before = (int) $usage['awarded_positive_points'];
            $after = $before + max(0, $effectivePoints);

            $this->pdo->prepare(
                'UPDATE reputation_daily_usage
                 SET event_count = event_count + 1,
                     awarded_positive_points = :awarded,
                     updated_at = UTC_TIMESTAMP()
                 WHERE user_id = :user_id AND category = :category AND usage_date = UTC_DATE()'
            )->execute([
                'awarded' => $after,
                'user_id' => $userId,
                'category' => $category,
            ]);

            $this->pdo->prepare(
                'INSERT INTO reputation_event_details
                 (event_id, requested_points, effective_points, multiplier,
                  daily_points_before, daily_points_after, created_at)
                 VALUES
                 (:event_id, :requested, :effective, :multiplier, :before_points, :after_points, UTC_TIMESTAMP())'
            )->execute([
                'event_id' => $eventId,
                'requested' => $points,
                'effective' => $effectivePoints,
                'multiplier' => $multiplier,
                'before_points' => $before,
                'after_points' => $after,
            ]);

            if ($started) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->refreshBadges($userId);
        $this->refreshAchievements($userId);
        $this->detectAnomalies($userId, $category);

        return $effectivePoints !== 0;
    }

    public function awardHelpfulReaction(string $reactorUserId, string $postId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT author_user_id FROM community_posts
             WHERE id = :post_id AND status = "PUBLISHED" LIMIT 1'
        );
        $stmt->execute(['post_id' => $postId]);
        $author = $stmt->fetchColumn();

        if (!is_string($author) || $author === '' || $author === $reactorUserId) {
            return false;
        }

        return $this->award(
            $author,
            'COMMUNITY',
            2,
            'POST_MARKED_HELPFUL',
            'COMMUNITY_POST',
            $postId,
            'helpful:' . $postId . ':' . $reactorUserId
        );
    }

    public function score(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT category, COALESCE(SUM(points), 0) AS points
             FROM reputation_events
             WHERE user_id = :user_id
             GROUP BY category'
        );
        $stmt->execute(['user_id' => $userId]);

        $categories = array_fill_keys(self::CATEGORIES, 0);
        foreach ($stmt->fetchAll() as $row) {
            $categories[(string) $row['category']] = (int) $row['points'];
        }

        $total = array_sum($categories);

        return [
            'total' => $total,
            'categories' => $categories,
            'level' => $this->level($total),
            'badges' => $this->badges($userId),
            'achievements' => $this->achievements($userId),
            'daily' => $this->dailyOverview($userId),
        ];
    }

    public function history(string $userId, int $limit = 100): array
    {
        $limit = max(1, min(250, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT re.id, re.category, re.points, re.reason_key, re.source_type, re.source_id,
                    re.created_at, red.requested_points, red.effective_points, red.multiplier,
                    red.daily_points_before, red.daily_points_after
             FROM reputation_events re
             LEFT JOIN reputation_event_details red ON red.event_id = re.id
             WHERE re.user_id = :user_id
             ORDER BY re.created_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function leaderboard(
        string $category = 'TOTAL',
        ?string $regionLevel = null,
        ?string $regionValue = null,
        int $limit = 50
    ): array {
        $category = strtoupper(trim($category));
        if ($category !== 'TOTAL' && !in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException('Ungültige Leaderboard-Kategorie.');
        }

        $limit = max(1, min(100, $limit));
        $where = ['cp.status = "ACTIVE"', 'cp.leaderboard_opt_in = 1'];
        $params = [];

        $regionColumn = match (strtoupper(trim((string) $regionLevel))) {
            'STATE' => 'cp.region_state',
            'DISTRICT' => 'cp.region_district',
            'CITY' => 'cp.region_city',
            default => null,
        };

        if ($regionColumn !== null && $regionValue !== null && trim($regionValue) !== '') {
            $where[] = $regionColumn . ' = :region_value';
            $params['region_value'] = trim($regionValue);
        }

        $joinCondition = $category === 'TOTAL'
            ? 're.user_id = cp.user_id'
            : 're.user_id = cp.user_id AND re.category = :category';

        if ($category !== 'TOTAL') {
            $params['category'] = $category;
        }

        $stmt = $this->pdo->prepare(
            'SELECT cp.username, cp.region_state, cp.region_district, cp.region_city,
                    COALESCE(SUM(re.points), 0) AS points
             FROM community_profiles cp
             LEFT JOIN reputation_events re ON ' . $joinCondition . '
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY cp.user_id
             ORDER BY points DESC, cp.username ASC
             LIMIT ' . $limit
        );
        $stmt->execute($params);

        $rows = $stmt->fetchAll();
        foreach ($rows as $index => &$row) {
            $row['rank'] = $index + 1;
            $row['points'] = (int) $row['points'];
        }
        unset($row);

        return $rows;
    }

    public function anomalies(string $adminUserId, string $status = 'OPEN'): array
    {
        $this->assertAdmin($adminUserId);
        $status = strtoupper(trim($status));
        if (!in_array($status, ['OPEN','RESOLVED','ALL'], true)) {
            $status = 'OPEN';
        }

        $where = $status === 'ALL' ? '' : 'WHERE ra.status = :status';
        $stmt = $this->pdo->prepare(
            'SELECT ra.*, cp.username
             FROM reputation_anomalies ra
             LEFT JOIN community_profiles cp ON cp.user_id = ra.user_id
             ' . $where . '
             ORDER BY FIELD(ra.severity, "CRITICAL","HIGH","MEDIUM","LOW"), ra.risk_score DESC, ra.created_at DESC'
        );
        $stmt->execute($status === 'ALL' ? [] : ['status' => $status]);

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['evidence'] = $this->decodeJson((string) $row['evidence_json']);
        }
        unset($row);

        return $rows;
    }

    public function resolveAnomaly(
        string $adminUserId,
        string $anomalyId,
        string $resolution
    ): void {
        $this->assertAdmin($adminUserId);
        $resolution = trim($resolution);
        if (mb_strlen($resolution) < 5 || mb_strlen($resolution) > 1000) {
            throw new \InvalidArgumentException('Eine nachvollziehbare Entscheidung ist erforderlich.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE reputation_anomalies
             SET status = "RESOLVED", reviewed_by = :reviewed_by,
                 resolution = :resolution, reviewed_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = "OPEN"'
        );
        $stmt->execute([
            'reviewed_by' => $adminUserId,
            'resolution' => $resolution,
            'id' => $anomalyId,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Offene Reputationsanomalie nicht gefunden.');
        }

        $this->audit?->log('REPUTATION_ANOMALY_RESOLVED', 'reputation_anomaly', $anomalyId, 'USER', $adminUserId);
    }

    public function adminCorrection(
        string $adminUserId,
        string $userId,
        string $category,
        int $points,
        string $reason
    ): string {
        $this->assertAdmin($adminUserId);
        $category = $this->category($category);

        if ($points < -500 || $points > 500 || $points === 0) {
            throw new \InvalidArgumentException('Admin-Korrektur muss zwischen -500 und +500 Punkten liegen.');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
            throw new \InvalidArgumentException('Korrekturbegründung ist erforderlich.');
        }

        $correctionId = Uuid::v4();
        $eventId = Uuid::v4();

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO reputation_events
                 (id, user_id, category, points, reason_key, source_type, source_id, unique_key, created_at)
                 VALUES
                 (:id, :user_id, :category, :points, "ADMIN_CORRECTION",
                  "ADMIN_CORRECTION", :source_id, :unique_key, UTC_TIMESTAMP())'
            )->execute([
                'id' => $eventId,
                'user_id' => $userId,
                'category' => $category,
                'points' => $points,
                'source_id' => $correctionId,
                'unique_key' => 'admin-correction:' . $correctionId,
            ]);

            $this->pdo->prepare(
                'INSERT INTO reputation_event_details
                 (event_id, requested_points, effective_points, multiplier,
                  daily_points_before, daily_points_after, created_at)
                 VALUES (:event_id, :requested_points, :effective_points, 1.00, 0, 0, UTC_TIMESTAMP())'
            )->execute([
                'event_id' => $eventId,
                'requested_points' => $points,
                'effective_points' => $points,
            ]);

            $this->pdo->prepare(
                'INSERT INTO reputation_admin_corrections
                 (id, user_id, admin_user_id, category, points, reason, event_id, created_at)
                 VALUES
                 (:id, :user_id, :admin_user_id, :category, :points, :reason, :event_id, UTC_TIMESTAMP())'
            )->execute([
                'id' => $correctionId,
                'user_id' => $userId,
                'admin_user_id' => $adminUserId,
                'category' => $category,
                'points' => $points,
                'reason' => $reason,
                'event_id' => $eventId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->refreshBadges($userId);
        $this->refreshAchievements($userId);

        $this->audit?->log('REPUTATION_ADMIN_CORRECTION', 'user', $userId, 'USER', $adminUserId, [
            'correction_id' => $correctionId,
            'category' => $category,
            'points' => $points,
        ]);

        return $correctionId;
    }

    public function adminCorrectionByUsername(
        string $adminUserId,
        string $username,
        string $category,
        int $points,
        string $reason
    ): string {
        $this->assertAdmin($adminUserId);

        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM community_profiles
             WHERE LOWER(username) = LOWER(:username) LIMIT 1'
        );
        $stmt->execute(['username' => trim($username)]);
        $userId = $stmt->fetchColumn();

        if (!is_string($userId) || $userId === '') {
            throw new \DomainException('Community-Nutzer wurde nicht gefunden.');
        }

        return $this->adminCorrection(
            $adminUserId,
            $userId,
            $category,
            $points,
            $reason
        );
    }

    public function recentCorrections(string $adminUserId, int $limit = 50): array
    {
        $this->assertAdmin($adminUserId);
        $limit = max(1, min(100, $limit));

        $stmt = $this->pdo->query(
            'SELECT rac.*, cp.username, admin.email AS admin_email
             FROM reputation_admin_corrections rac
             LEFT JOIN community_profiles cp ON cp.user_id = rac.user_id
             INNER JOIN users admin ON admin.id = rac.admin_user_id
             ORDER BY rac.created_at DESC
             LIMIT ' . $limit
        );

        return $stmt->fetchAll();
    }

    public function policies(): array
    {
        $stmt = $this->pdo->query(
            'SELECT category, daily_positive_cap, full_rate_events, reduced_rate_events,
                    reduced_multiplier, tail_multiplier
             FROM reputation_policies
             WHERE active = 1
             ORDER BY category'
        );

        return $stmt->fetchAll();
    }

    private function lockDailyUsage(string $userId, string $category): array
    {
        $this->pdo->prepare(
            'INSERT IGNORE INTO reputation_daily_usage
             (user_id, category, usage_date, event_count, awarded_positive_points, updated_at)
             VALUES (:user_id, :category, UTC_DATE(), 0, 0, UTC_TIMESTAMP())'
        )->execute([
            'user_id' => $userId,
            'category' => $category,
        ]);

        $stmt = $this->pdo->prepare(
            'SELECT event_count, awarded_positive_points
             FROM reputation_daily_usage
             WHERE user_id = :user_id AND category = :category AND usage_date = UTC_DATE()
             FOR UPDATE'
        );
        $stmt->execute([
            'user_id' => $userId,
            'category' => $category,
        ]);
        $row = $stmt->fetch();

        return is_array($row)
            ? $row
            : ['event_count' => 0, 'awarded_positive_points' => 0];
    }

    private function policy(string $category): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM reputation_policies WHERE category = :category AND active = 1 LIMIT 1'
        );
        $stmt->execute(['category' => $category]);
        $row = $stmt->fetch();

        return is_array($row)
            ? $row
            : [
                'daily_positive_cap' => 30,
                'full_rate_events' => 5,
                'reduced_rate_events' => 15,
                'reduced_multiplier' => 0.50,
                'tail_multiplier' => 0.25,
            ];
    }

    private function multiplier(int $priorEvents, array $policy): float
    {
        if ($priorEvents < (int) $policy['full_rate_events']) {
            return 1.0;
        }

        if ($priorEvents < (int) $policy['reduced_rate_events']) {
            return (float) $policy['reduced_multiplier'];
        }

        return (float) $policy['tail_multiplier'];
    }

    private function dailyOverview(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rdu.category, rdu.event_count, rdu.awarded_positive_points,
                    rp.daily_positive_cap
             FROM reputation_daily_usage rdu
             INNER JOIN reputation_policies rp ON rp.category = rdu.category
             WHERE rdu.user_id = :user_id AND rdu.usage_date = UTC_DATE()'
        );
        $stmt->execute(['user_id' => $userId]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(string) $row['category']] = [
                'event_count' => (int) $row['event_count'],
                'awarded_positive_points' => (int) $row['awarded_positive_points'],
                'daily_positive_cap' => (int) $row['daily_positive_cap'],
            ];
        }

        return $rows;
    }

    private function refreshAchievements(string $userId): void
    {
        $achievements = $this->pdo->query(
            'SELECT achievement_key, metric, category, target_value
             FROM community_achievements WHERE active = 1'
        )->fetchAll();

        foreach ($achievements as $achievement) {
            $metric = (string) $achievement['metric'];
            $category = $achievement['category'] !== null ? (string) $achievement['category'] : null;
            $progress = match ($metric) {
                'POSITIVE_EVENTS' => $this->positiveEventCount($userId),
                'CATEGORY_POINTS' => $category === null ? 0 : max(0, $this->categoryPoints($userId, $category)),
                'ACTIVE_DAYS' => $this->activeDays($userId),
                default => 0,
            };

            $unlocked = $progress >= (int) $achievement['target_value'];
            $this->pdo->prepare(
                'INSERT INTO community_user_achievements
                 (user_id, achievement_key, progress_value, unlocked_at, updated_at)
                 VALUES (:user_id, :achievement_key, :progress_value,
                         IF(:unlocked = 1, UTC_TIMESTAMP(), NULL), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    progress_value = VALUES(progress_value),
                    unlocked_at = CASE
                        WHEN unlocked_at IS NOT NULL THEN unlocked_at
                        WHEN VALUES(progress_value) >= :target_value THEN UTC_TIMESTAMP()
                        ELSE NULL
                    END,
                    updated_at = VALUES(updated_at)'
            )->execute([
                'user_id' => $userId,
                'achievement_key' => $achievement['achievement_key'],
                'progress_value' => $progress,
                'unlocked' => $unlocked ? 1 : 0,
                'target_value' => (int) $achievement['target_value'],
            ]);
        }
    }

    private function achievements(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.achievement_key, a.label, a.description, a.metric, a.category, a.target_value,
                    COALESCE(ua.progress_value, 0) AS progress_value, ua.unlocked_at
             FROM community_achievements a
             LEFT JOIN community_user_achievements ua
                ON ua.achievement_key = a.achievement_key AND ua.user_id = :user_id
             WHERE a.active = 1
             ORDER BY (ua.unlocked_at IS NULL), a.target_value, a.label'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    private function detectAnomalies(string $userId, string $category): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT event_count, awarded_positive_points
             FROM reputation_daily_usage
             WHERE user_id = :user_id AND category = :category AND usage_date = UTC_DATE()
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'category' => $category]);
        $usage = $stmt->fetch();

        if (!is_array($usage)) {
            return;
        }

        $eventCount = (int) $usage['event_count'];
        $points = (int) $usage['awarded_positive_points'];
        $policy = $this->policy($category);

        if ($eventCount >= 25) {
            $this->createAnomaly(
                $userId,
                'HIGH_EVENT_VELOCITY',
                $eventCount >= 50 ? 'HIGH' : 'MEDIUM',
                min(100, 50 + $eventCount),
                [
                    'category' => $category,
                    'events_today' => $eventCount,
                    'awarded_points_today' => $points,
                ]
            );
        }

        if ($points >= (int) $policy['daily_positive_cap']) {
            $this->createAnomaly(
                $userId,
                'DAILY_CAP_REACHED',
                'LOW',
                40,
                [
                    'category' => $category,
                    'awarded_points_today' => $points,
                    'daily_cap' => (int) $policy['daily_positive_cap'],
                ]
            );
        }

        $burst = $this->pdo->prepare(
            'SELECT COUNT(*) FROM reputation_events
             WHERE user_id = :user_id AND points > 0
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)'
        );
        $burst->execute(['user_id' => $userId]);
        $burstCount = (int) $burst->fetchColumn();

        if ($burstCount >= 15) {
            $this->createAnomaly(
                $userId,
                'REPUTATION_BURST',
                'HIGH',
                min(100, 65 + $burstCount),
                ['positive_events_last_5_minutes' => $burstCount]
            );
        }
    }

    private function createAnomaly(
        string $userId,
        string $type,
        string $severity,
        int $riskScore,
        array $evidence
    ): void {
        $existing = $this->pdo->prepare(
            'SELECT id FROM reputation_anomalies
             WHERE user_id = :user_id AND anomaly_type = :type AND status = "OPEN"
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
             LIMIT 1'
        );
        $existing->execute(['user_id' => $userId, 'type' => $type]);
        if ($existing->fetchColumn() !== false) {
            return;
        }

        $this->pdo->prepare(
            'INSERT INTO reputation_anomalies
             (id, user_id, anomaly_type, severity, risk_score, evidence_json, status,
              reviewed_by, resolution, created_at, reviewed_at)
             VALUES
             (:id, :user_id, :type, :severity, :risk_score, :evidence, "OPEN",
              NULL, NULL, UTC_TIMESTAMP(), NULL)'
        )->execute([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'type' => $type,
            'severity' => $severity,
            'risk_score' => max(0, min(100, $riskScore)),
            'evidence' => json_encode(
                $evidence,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
        ]);
    }

    private function refreshBadges(string $userId): void
    {
        $score = $this->rawTotal($userId);

        $conditions = [
            'COMMUNITY_STARTER' => $score >= 1,
            'COMMUNITY_HELPER' => $score >= 10,
            'COMMUNITY_CONTRIBUTOR' => $score >= 50,
        ];

        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO community_user_badges
             (user_id, badge_key, awarded_at, source_key)
             VALUES (:user_id, :badge_key, UTC_TIMESTAMP(), :source_key)'
        );

        foreach ($conditions as $badge => $met) {
            if ($met) {
                $insert->execute([
                    'user_id' => $userId,
                    'badge_key' => $badge,
                    'source_key' => 'score:' . $score,
                ]);
            }
        }
    }

    private function rawTotal(string $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(points), 0) FROM reputation_events WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    private function categoryPoints(string $userId, string $category): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(points), 0)
             FROM reputation_events WHERE user_id = :user_id AND category = :category'
        );
        $stmt->execute(['user_id' => $userId, 'category' => $category]);

        return (int) $stmt->fetchColumn();
    }

    private function positiveEventCount(string $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM reputation_events WHERE user_id = :user_id AND points > 0'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    private function activeDays(string $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT DATE(created_at))
             FROM reputation_events WHERE user_id = :user_id AND points > 0'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    private function badges(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.badge_key, b.label, b.description, ub.awarded_at
             FROM community_user_badges ub
             INNER JOIN community_badges b ON b.badge_key = ub.badge_key AND b.active = 1
             WHERE ub.user_id = :user_id
             ORDER BY ub.awarded_at'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    private function uniqueKeyExists(string $uniqueKey): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM reputation_events WHERE unique_key = :unique_key'
        );
        $stmt->execute(['unique_key' => $uniqueKey]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function assertAdmin(string $userId): void
    {
        if ($this->permissions === null) {
            throw new AuthorizationException('Admin-Berechtigungsprüfung ist nicht konfiguriert.');
        }

        if (
            !$this->permissions->hasRole($userId, 'SUPER_ADMIN')
            && !$this->permissions->hasRole($userId, 'ADMIN')
            && !$this->permissions->can($userId, 'admin.system')
        ) {
            throw new AuthorizationException('Access denied.');
        }
    }

    private function category(string $category): string
    {
        $category = strtoupper(trim($category));
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException('Ungültige Reputationskategorie.');
        }

        return $category;
    }

    private function decodeJson(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function level(int $points): array
    {
        $level = max(1, (int) floor(max(0, $points) / 25) + 1);
        $currentFloor = ($level - 1) * 25;
        $next = $level * 25;

        return [
            'level' => $level,
            'points_into_level' => max(0, $points - $currentFloor),
            'points_for_next_level' => max(1, $next - $currentFloor),
        ];
    }
}
