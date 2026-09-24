<?php

declare(strict_types=1);

namespace MeldeVerkehr\Community;

use MeldeVerkehr\Support\Uuid;
use PDO;

final class CommunityAbuseService
{
    private const ACTIONS = ['POST','COMMENT','MESSAGE','REPORT'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function assertAllowed(string $userId, string $actionType, ?string $content = null): void
    {
        $actionType = $this->normalizeAction($actionType);
        $this->assertNotRestricted($userId, $actionType);

        $windowMinutes = match ($actionType) {
            'POST', 'COMMENT' => 10,
            'MESSAGE', 'REPORT' => 60,
        };
        $hardLimit = match ($actionType) {
            'POST' => 6,
            'COMMENT' => 20,
            'MESSAGE' => 30,
            'REPORT' => 8,
        };

        $recentCount = $this->countSignals($userId, $actionType, $windowMinutes);
        if ($recentCount >= $hardLimit) {
            $this->createFlag(
                $userId,
                $actionType . '_VELOCITY',
                'HIGH',
                90,
                [
                    'action_type' => $actionType,
                    'window_minutes' => $windowMinutes,
                    'recent_count' => $recentCount,
                    'hard_limit' => $hardLimit,
                ]
            );

            throw new \DomainException('Aktion wurde wegen ungewöhnlich hoher Aktivität vorübergehend blockiert.');
        }

        $hash = $this->contentHash($content);
        if ($hash !== null) {
            $duplicateLimit = match ($actionType) {
                'POST' => 2,
                'COMMENT' => 4,
                'MESSAGE' => 4,
                'REPORT' => 1000,
            };

            if ($duplicateLimit < 1000) {
                $duplicates = $this->countContentHash($userId, $actionType, $hash, 10);
                if ($duplicates >= $duplicateLimit) {
                    $this->createFlag(
                        $userId,
                        $actionType . '_DUPLICATE_CONTENT',
                        'HIGH',
                        85,
                        [
                            'action_type' => $actionType,
                            'window_minutes' => 10,
                            'matching_content_count' => $duplicates,
                            'content_hash' => $hash,
                        ]
                    );

                    throw new \DomainException('Wiederholte identische Inhalte wurden als mögliches Spam-Muster erkannt.');
                }
            }
        }

        $allRecent = $this->countAllSignals($userId, 10);
        if ($allRecent >= 35) {
            $this->createFlag(
                $userId,
                'BOT_LIKE_ACTIVITY',
                'CRITICAL',
                100,
                [
                    'window_minutes' => 10,
                    'all_actions' => $allRecent,
                ]
            );

            throw new \DomainException('Aktion wurde wegen eines automatisiert wirkenden Aktivitätsmusters blockiert.');
        }
    }

    public function record(
        string $userId,
        string $actionType,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $content = null,
        array $metadata = []
    ): void {
        $actionType = $this->normalizeAction($actionType);
        $hash = $this->contentHash($content);

        $stmt = $this->pdo->prepare(
            'INSERT INTO community_activity_signals
             (user_id, action_type, target_type, target_id, content_hash, metadata_json, created_at)
             VALUES
             (:user_id, :action_type, :target_type, :target_id, :content_hash, :metadata_json, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'user_id' => $userId,
            'action_type' => $actionType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'content_hash' => $hash,
            'metadata_json' => $metadata === []
                ? null
                : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);

        if ($actionType === 'REPORT') {
            $tenMinutes = $this->countSignals($userId, 'REPORT', 10);
            if ($tenMinutes >= 5) {
                $this->createFlag(
                    $userId,
                    'MASS_REPORT_PATTERN',
                    'MEDIUM',
                    min(85, 50 + ($tenMinutes * 5)),
                    [
                        'window_minutes' => 10,
                        'report_count' => $tenMinutes,
                    ]
                );
            }
        }

        if (in_array($actionType, ['POST','COMMENT','MESSAGE'], true)) {
            $shortBurst = $this->countSignals($userId, $actionType, 2);
            $threshold = match ($actionType) {
                'POST' => 4,
                'COMMENT' => 8,
                'MESSAGE' => 10,
            };

            if ($shortBurst >= $threshold) {
                $this->createFlag(
                    $userId,
                    'BOT_LIKE_' . $actionType . '_BURST',
                    'MEDIUM',
                    65,
                    [
                        'action_type' => $actionType,
                        'window_minutes' => 2,
                        'count' => $shortBurst,
                    ]
                );
            }
        }
    }

    public function attachReportRisk(string $reportId, string $reporterUserId): array
    {
        $hour = $this->countSignals($reporterUserId, 'REPORT', 60);
        $tenMinutes = $this->countSignals($reporterUserId, 'REPORT', 10);
        $openFlags = $this->countOpenFlags($reporterUserId);

        $score = min(100, ($hour * 6) + ($tenMinutes * 8) + ($openFlags * 15));
        $indicators = [
            'reports_last_hour' => $hour,
            'reports_last_10_minutes' => $tenMinutes,
            'open_abuse_flags' => $openFlags,
        ];

        $this->pdo->prepare(
            'INSERT INTO community_report_risk
             (report_id, risk_score, indicators_json, created_at)
             VALUES (:report_id, :risk_score, :indicators_json, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                risk_score = VALUES(risk_score),
                indicators_json = VALUES(indicators_json),
                created_at = VALUES(created_at)'
        )->execute([
            'report_id' => $reportId,
            'risk_score' => $score,
            'indicators_json' => json_encode(
                $indicators,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
        ]);

        return ['score' => $score, 'indicators' => $indicators];
    }

    public function restrict(
        string $userId,
        string $scope,
        int $hours,
        ?string $reason,
        ?string $actorId
    ): void {
        $scope = strtoupper(trim($scope));
        if (!in_array($scope, ['POSTING','MESSAGING','REPORTING','COMMUNITY'], true)) {
            throw new \InvalidArgumentException('Ungültiger Einschränkungsbereich.');
        }

        $hours = max(1, min(24 * 30, $hours));
        $until = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $hours . ' hours')
            ->format('Y-m-d H:i:s');

        $posting = in_array($scope, ['POSTING','COMMUNITY'], true) ? $until : null;
        $messaging = in_array($scope, ['MESSAGING','COMMUNITY'], true) ? $until : null;
        $reporting = in_array($scope, ['REPORTING','COMMUNITY'], true) ? $until : null;

        $this->pdo->prepare(
            'INSERT INTO community_user_moderation_state
             (user_id, posting_restricted_until, messaging_restricted_until, reporting_restricted_until,
              reason, updated_by, updated_at)
             VALUES
             (:user_id, :posting, :messaging, :reporting, :reason, :updated_by, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                posting_restricted_until = GREATEST(
                    COALESCE(posting_restricted_until, "1970-01-01 00:00:00"),
                    COALESCE(VALUES(posting_restricted_until), "1970-01-01 00:00:00")
                ),
                messaging_restricted_until = GREATEST(
                    COALESCE(messaging_restricted_until, "1970-01-01 00:00:00"),
                    COALESCE(VALUES(messaging_restricted_until), "1970-01-01 00:00:00")
                ),
                reporting_restricted_until = GREATEST(
                    COALESCE(reporting_restricted_until, "1970-01-01 00:00:00"),
                    COALESCE(VALUES(reporting_restricted_until), "1970-01-01 00:00:00")
                ),
                reason = VALUES(reason),
                updated_by = VALUES(updated_by),
                updated_at = VALUES(updated_at)'
        )->execute([
            'user_id' => $userId,
            'posting' => $posting,
            'messaging' => $messaging,
            'reporting' => $reporting,
            'reason' => $reason === null ? null : mb_substr(trim($reason), 0, 1000),
            'updated_by' => $actorId,
        ]);
    }

    public function clearRestriction(string $userId, ?string $actorId = null): void
    {
        $this->pdo->prepare(
            'UPDATE community_user_moderation_state
             SET posting_restricted_until = NULL,
                 messaging_restricted_until = NULL,
                 reporting_restricted_until = NULL,
                 reason = NULL,
                 updated_by = :updated_by,
                 updated_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id'
        )->execute([
            'updated_by' => $actorId,
            'user_id' => $userId,
        ]);
    }

    public function state(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM community_user_moderation_state WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function assertNotRestricted(string $userId, string $actionType): void
    {
        $column = match ($actionType) {
            'POST', 'COMMENT' => 'posting_restricted_until',
            'MESSAGE' => 'messaging_restricted_until',
            'REPORT' => 'reporting_restricted_until',
        };

        $stmt = $this->pdo->prepare(
            sprintf(
                'SELECT %s FROM community_user_moderation_state
                 WHERE user_id = :user_id AND %s IS NOT NULL AND %s > UTC_TIMESTAMP()
                 LIMIT 1',
                $column,
                $column,
                $column
            )
        );
        $stmt->execute(['user_id' => $userId]);
        $until = $stmt->fetchColumn();

        if (is_string($until) && $until !== '') {
            throw new \DomainException('Diese Community-Funktion ist bis ' . $until . ' UTC eingeschränkt.');
        }
    }

    private function createFlag(
        string $userId,
        string $signalType,
        string $severity,
        int $riskScore,
        array $evidence,
        ?string $relatedReportId = null
    ): string {
        $existing = $this->pdo->prepare(
            'SELECT id FROM community_abuse_flags
             WHERE user_id = :user_id AND signal_type = :signal_type
               AND status IN ("OPEN","IN_REVIEW")
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)
             ORDER BY created_at DESC LIMIT 1'
        );
        $existing->execute([
            'user_id' => $userId,
            'signal_type' => $signalType,
        ]);
        $id = $existing->fetchColumn();

        if (is_string($id) && $id !== '') {
            return $id;
        }

        $id = Uuid::v4();
        $this->pdo->prepare(
            'INSERT INTO community_abuse_flags
             (id, user_id, signal_type, severity, risk_score, status, related_report_id,
              evidence_json, reviewed_by, reviewed_at, resolution, created_at)
             VALUES
             (:id, :user_id, :signal_type, :severity, :risk_score, "OPEN", :related_report_id,
              :evidence_json, NULL, NULL, NULL, UTC_TIMESTAMP())'
        )->execute([
            'id' => $id,
            'user_id' => $userId,
            'signal_type' => $signalType,
            'severity' => $severity,
            'risk_score' => max(0, min(100, $riskScore)),
            'related_report_id' => $relatedReportId,
            'evidence_json' => json_encode(
                $evidence,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
        ]);

        return $id;
    }

    private function countSignals(string $userId, string $actionType, int $minutes): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM community_activity_signals
             WHERE user_id = :user_id AND action_type = :action_type
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . max(1, $minutes) . ' MINUTE)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'action_type' => $actionType,
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function countAllSignals(string $userId, int $minutes): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM community_activity_signals
             WHERE user_id = :user_id
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . max(1, $minutes) . ' MINUTE)'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    private function countContentHash(string $userId, string $actionType, string $hash, int $minutes): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM community_activity_signals
             WHERE user_id = :user_id AND action_type = :action_type AND content_hash = :content_hash
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . max(1, $minutes) . ' MINUTE)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'action_type' => $actionType,
            'content_hash' => $hash,
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function countOpenFlags(string $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM community_abuse_flags
             WHERE user_id = :user_id AND status IN ("OPEN","IN_REVIEW")'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    private function normalizeAction(string $actionType): string
    {
        $actionType = strtoupper(trim($actionType));
        if (!in_array($actionType, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unbekannter Community-Aktionstyp.');
        }

        return $actionType;
    }

    private function contentHash(?string $content): ?string
    {
        $content = trim((string) ($content ?? ''));
        if ($content === '') {
            return null;
        }

        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $content) ?? $content, 'UTF-8');

        return hash('sha256', $normalized);
    }
}
