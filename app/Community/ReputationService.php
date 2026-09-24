<?php

declare(strict_types=1);

namespace MeldeVerkehr\Community;

use MeldeVerkehr\Support\Uuid;
use PDO;

final class ReputationService
{
    private const CATEGORIES = ['COMMUNITY','REPORT_QUALITY','PROBLEM_REPORT','AUTHORITY_INFO'];

    public function __construct(private readonly PDO $pdo)
    {
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
        $category = strtoupper(trim($category));

        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException('Ungültige Reputationskategorie.');
        }

        if ($points < -100 || $points > 100 || $points === 0) {
            throw new \InvalidArgumentException('Ungültige Punktezahl.');
        }

        $reasonKey = strtoupper(trim($reasonKey));
        if ($reasonKey === '' || strlen($reasonKey) > 80) {
            throw new \InvalidArgumentException('Ungültiger Reputationsgrund.');
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO reputation_events
                 (id, user_id, category, points, reason_key, source_type, source_id, unique_key, created_at)
                 VALUES
                 (:id, :user_id, :category, :points, :reason_key, :source_type, :source_id, :unique_key, UTC_TIMESTAMP())'
            );
            $stmt->execute([
                'id' => Uuid::v4(),
                'user_id' => $userId,
                'category' => $category,
                'points' => $points,
                'reason_key' => $reasonKey,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'unique_key' => $uniqueKey,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' && $uniqueKey !== null) {
                return false;
            }
            throw $e;
        }

        $this->refreshBadges($userId);

        return true;
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

        return [
            'total' => array_sum($categories),
            'categories' => $categories,
            'level' => $this->level(array_sum($categories)),
            'badges' => $this->badges($userId),
        ];
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
