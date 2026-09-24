<?php

declare(strict_types=1);

namespace MeldeVerkehr\Community;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Uuid;
use PDO;

final class CommunityService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization,
        private readonly SecretCipher $cipher,
        private readonly AuditLogger $audit
    ) {
    }

    public function saveProfile(string $userId, array $input): array
    {
        $username = trim((string) ($input['username'] ?? ''));
        if (
            !preg_match('/^[A-Za-z0-9._-]{3,30}$/', $username)
            || str_starts_with($username, '.')
            || str_ends_with($username, '.')
        ) {
            throw new \InvalidArgumentException('Benutzername muss 3–30 Zeichen lang sein und darf nur Buchstaben, Zahlen, Punkt, Unterstrich und Bindestrich enthalten.');
        }

        $bio = trim((string) ($input['bio'] ?? ''));
        if (mb_strlen($bio) > 1000) {
            throw new \InvalidArgumentException('Bio ist zu lang.');
        }

        $visibility = [
            'bio' => $this->visibility((string) ($input['visibility_bio'] ?? 'PUBLIC')),
            'region_state' => $this->visibility((string) ($input['visibility_state'] ?? 'PUBLIC')),
            'region_district' => $this->visibility((string) ($input['visibility_district'] ?? 'LOGGED_IN')),
            'region_city' => $this->visibility((string) ($input['visibility_city'] ?? 'LOGGED_IN')),
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO community_profiles
             (user_id, username, bio, region_country, region_state, region_district, region_city,
              visibility_json, leaderboard_opt_in, status, created_at, updated_at)
             VALUES
             (:user_id, :username, :bio, "DE", :state, :district, :city,
              :visibility, :leaderboard_opt_in, "ACTIVE", UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                username = VALUES(username),
                bio = VALUES(bio),
                region_state = VALUES(region_state),
                region_district = VALUES(region_district),
                region_city = VALUES(region_city),
                visibility_json = VALUES(visibility_json),
                leaderboard_opt_in = VALUES(leaderboard_opt_in),
                updated_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([
            'user_id' => $userId,
            'username' => $username,
            'bio' => $bio === '' ? null : $bio,
            'state' => $this->nullable($input['region_state'] ?? null),
            'district' => $this->nullable($input['region_district'] ?? null),
            'city' => $this->nullable($input['region_city'] ?? null),
            'visibility' => json_encode($visibility, JSON_THROW_ON_ERROR),
            'leaderboard_opt_in' => !empty($input['leaderboard_opt_in']) ? 1 : 0,
        ]);

        $this->audit->log('COMMUNITY_PROFILE_SAVED', 'community_profile', $userId, 'USER', $userId);

        return $this->profileByUser($userId, $userId)
            ?? throw new \RuntimeException('Community-Profil konnte nicht geladen werden.');
    }

    public function profileByUsername(?string $viewerUserId, string $username): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM community_profiles
             WHERE LOWER(username) = LOWER(:username) AND status = "ACTIVE"
             LIMIT 1'
        );
        $stmt->execute(['username' => trim($username)]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->visibleProfile($row, $viewerUserId) : null;
    }

    public function profileByUser(?string $viewerUserId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM community_profiles WHERE user_id = :user_id AND status = "ACTIVE" LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->visibleProfile($row, $viewerUserId) : null;
    }

    public function createGroup(string $userId, array $input): array
    {
        $type = strtoupper(trim((string) ($input['group_type'] ?? 'INTEREST')));
        if (!in_array($type, ['REGION','INTEREST'], true)) {
            throw new \InvalidArgumentException('Ungültiger Gruppentyp.');
        }

        $name = trim((string) ($input['name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 190) {
            throw new \InvalidArgumentException('Gruppenname ist ungültig.');
        }

        $description = trim((string) ($input['description'] ?? ''));
        if (mb_strlen($description) > 1000) {
            throw new \InvalidArgumentException('Gruppenbeschreibung ist zu lang.');
        }

        $slug = $this->uniqueSlug($name);
        $id = Uuid::v4();

        $stmt = $this->pdo->prepare(
            'INSERT INTO community_groups
             (id, group_type, name, slug, description, region_level, region_code,
              created_by_user_id, status, created_at, updated_at)
             VALUES
             (:id, :group_type, :name, :slug, :description, :region_level, :region_code,
              :user_id, "ACTIVE", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'id' => $id,
            'group_type' => $type,
            'name' => $name,
            'slug' => $slug,
            'description' => $description === '' ? null : $description,
            'region_level' => $type === 'REGION' ? $this->nullable($input['region_level'] ?? null) : null,
            'region_code' => $type === 'REGION' ? $this->nullable($input['region_code'] ?? null) : null,
            'user_id' => $userId,
        ]);

        $this->pdo->prepare(
            'INSERT INTO community_group_memberships
             (group_id, user_id, role, status, joined_at)
             VALUES (:group_id, :user_id, "OWNER", "ACTIVE", UTC_TIMESTAMP())'
        )->execute(['group_id' => $id, 'user_id' => $userId]);

        return $this->group($id)
            ?? throw new \RuntimeException('Gruppe konnte nicht geladen werden.');
    }

    public function joinGroup(string $userId, string $groupId): void
    {
        if ($this->group($groupId) === null) {
            throw new \DomainException('Gruppe nicht gefunden.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO community_group_memberships
             (group_id, user_id, role, status, joined_at)
             VALUES (:group_id, :user_id, "MEMBER", "ACTIVE", UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE status = "ACTIVE", joined_at = VALUES(joined_at)'
        );
        $stmt->execute(['group_id' => $groupId, 'user_id' => $userId]);
    }

    public function groups(?string $userId = null): array
    {
        $sql =
            'SELECT g.*,
                    COUNT(m.user_id) AS member_count'
            . ($userId !== null
                ? ', MAX(CASE WHEN m.user_id = :viewer_id AND m.status = "ACTIVE" THEN 1 ELSE 0 END) AS joined'
                : ', 0 AS joined')
            . ' FROM community_groups g
                LEFT JOIN community_group_memberships m
                  ON m.group_id = g.id AND m.status = "ACTIVE"
                WHERE g.status = "ACTIVE"
                GROUP BY g.id
                ORDER BY g.group_type, g.name';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($userId !== null ? ['viewer_id' => $userId] : []);

        return $stmt->fetchAll();
    }

    public function createPost(string $userId, array $input): array
    {
        $this->authorization->authorize($userId, 'community.post.create');

        if ($this->profileByUser($userId, $userId) === null) {
            throw new \DomainException('Für Beiträge ist zuerst ein Community-Profil erforderlich.');
        }

        $body = trim((string) ($input['body'] ?? ''));
        if (mb_strlen($body) < 2 || mb_strlen($body) > 10000) {
            throw new \InvalidArgumentException('Beitrag muss zwischen 2 und 10.000 Zeichen lang sein.');
        }

        $groupId = $this->nullable($input['group_id'] ?? null);
        if ($groupId !== null && !$this->isActiveMember($userId, $groupId)) {
            throw new \DomainException('Beiträge in einer Gruppe erfordern eine aktive Mitgliedschaft.');
        }

        $problemAreaId = $this->nullable($input['problem_area_id'] ?? null);
        if ($problemAreaId !== null && !$this->publicProblemVisible($problemAreaId)) {
            throw new \DomainException('Öffentliche Problemstelle ist nicht freigegeben.');
        }

        $releaseId = $this->nullable($input['case_release_id'] ?? null);
        if ($releaseId !== null && !$this->ownedPublishedRelease($userId, $releaseId)) {
            throw new \DomainException('Fallfreigabe ist nicht veröffentlicht oder gehört nicht zum Nutzer.');
        }

        $id = Uuid::v4();
        $stmt = $this->pdo->prepare(
            'INSERT INTO community_posts
             (id, author_user_id, group_id, problem_area_id, case_release_id, topic, body, status,
              created_at, updated_at)
             VALUES
             (:id, :user_id, :group_id, :problem_area_id, :case_release_id, :topic, :body,
              "PUBLISHED", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'group_id' => $groupId,
            'problem_area_id' => $problemAreaId,
            'case_release_id' => $releaseId,
            'topic' => $this->shortNullable($input['topic'] ?? null, 100),
            'body' => $body,
        ]);

        $this->audit->log('COMMUNITY_POST_CREATED', 'community_post', $id, 'USER', $userId);

        return $this->post($userId, $id)
            ?? throw new \RuntimeException('Beitrag konnte nicht geladen werden.');
    }

    public function feed(?string $viewerUserId, ?string $groupId = null, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $where = ['p.status = "PUBLISHED"', 'cp.status = "ACTIVE"'];
        $params = [];

        if ($groupId !== null && trim($groupId) !== '') {
            $where[] = 'p.group_id = :group_id';
            $params['group_id'] = trim($groupId);
        }

        if ($viewerUserId !== null) {
            $where[] =
                'NOT EXISTS (
                    SELECT 1 FROM community_blocks b
                    WHERE (b.blocker_user_id = :viewer_a AND b.blocked_user_id = p.author_user_id)
                       OR (b.blocker_user_id = p.author_user_id AND b.blocked_user_id = :viewer_b)
                )';
            $params['viewer_a'] = $viewerUserId;
            $params['viewer_b'] = $viewerUserId;
        }

        $sql =
            'SELECT p.id, p.author_user_id, p.group_id, p.problem_area_id, p.case_release_id,
                    p.topic, p.body, p.created_at,
                    cp.username,
                    g.name AS group_name,
                    COUNT(DISTINCT c.id) AS comment_count,
                    COUNT(DISTINCT r.user_id) AS reaction_count
             FROM community_posts p
             INNER JOIN community_profiles cp ON cp.user_id = p.author_user_id
             LEFT JOIN community_groups g ON g.id = p.group_id
             LEFT JOIN community_comments c ON c.post_id = p.id AND c.status = "PUBLISHED"
             LEFT JOIN community_reactions r ON r.post_id = p.id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY p.id
             ORDER BY p.created_at DESC
             LIMIT ' . $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function post(?string $viewerUserId, string $postId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, cp.username, g.name AS group_name
             FROM community_posts p
             INNER JOIN community_profiles cp ON cp.user_id = p.author_user_id
             LEFT JOIN community_groups g ON g.id = p.group_id
             WHERE p.id = :id AND p.status = "PUBLISHED" AND cp.status = "ACTIVE"
             LIMIT 1'
        );
        $stmt->execute(['id' => $postId]);
        $post = $stmt->fetch();

        if (!is_array($post)) {
            return null;
        }

        if ($viewerUserId !== null && $this->blockedEitherDirection($viewerUserId, (string) $post['author_user_id'])) {
            return null;
        }

        $comments = $this->pdo->prepare(
            'SELECT c.id, c.author_user_id, c.parent_comment_id, c.body, c.created_at, cp.username
             FROM community_comments c
             INNER JOIN community_profiles cp ON cp.user_id = c.author_user_id
             WHERE c.post_id = :post_id AND c.status = "PUBLISHED" AND cp.status = "ACTIVE"
             ORDER BY c.created_at'
        );
        $comments->execute(['post_id' => $postId]);
        $post['comments'] = array_values(array_filter(
            $comments->fetchAll(),
            fn(array $comment): bool =>
                $viewerUserId === null
                || !$this->blockedEitherDirection($viewerUserId, (string) $comment['author_user_id'])
        ));

        return $post;
    }

    public function comment(string $userId, string $postId, string $body, ?string $parentId = null): array
    {
        $this->authorization->authorize($userId, 'community.comment.create');
        $post = $this->post($userId, $postId);

        if ($post === null) {
            throw new \DomainException('Beitrag nicht gefunden oder nicht zugänglich.');
        }

        $body = trim($body);
        if (mb_strlen($body) < 1 || mb_strlen($body) > 4000) {
            throw new \InvalidArgumentException('Kommentar ist ungültig.');
        }

        if ($parentId !== null) {
            $parentStmt = $this->pdo->prepare(
                'SELECT id FROM community_comments
                 WHERE id = :id AND post_id = :post_id AND status = "PUBLISHED" LIMIT 1'
            );
            $parentStmt->execute(['id' => $parentId, 'post_id' => $postId]);
            if ($parentStmt->fetchColumn() === false) {
                throw new \DomainException('Übergeordneter Kommentar existiert nicht.');
            }
        }

        $id = Uuid::v4();
        $this->pdo->prepare(
            'INSERT INTO community_comments
             (id, post_id, author_user_id, parent_comment_id, body, status, created_at, updated_at)
             VALUES (:id, :post_id, :user_id, :parent_id, :body, "PUBLISHED", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([
            'id' => $id,
            'post_id' => $postId,
            'user_id' => $userId,
            'parent_id' => $parentId,
            'body' => $body,
        ]);

        return ['id' => $id, 'post_id' => $postId, 'body' => $body];
    }

    public function reactHelpful(string $userId, string $postId): bool
    {
        $post = $this->post($userId, $postId);
        if ($post === null) {
            throw new \DomainException('Beitrag nicht gefunden oder nicht zugänglich.');
        }

        if ((string) $post['author_user_id'] === $userId) {
            throw new \DomainException('Eigene Beiträge können nicht als hilfreich bewertet werden.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO community_reactions
             (post_id, user_id, reaction, created_at)
             VALUES (:post_id, :user_id, "HELPFUL", UTC_TIMESTAMP())'
        );
        $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function createPublicProblemArea(string $userId, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 190) {
            throw new \InvalidArgumentException('Name der öffentlichen Problemstelle ist ungültig.');
        }

        $lat = $input['latitude'] ?? null;
        $lon = $input['longitude'] ?? null;
        $lat = is_numeric($lat) ? round((float) $lat, 4) : null;
        $lon = is_numeric($lon) ? round((float) $lon, 4) : null;

        if ($lat !== null && ($lat < -90 || $lat > 90)) {
            throw new \InvalidArgumentException('Breitengrad ist ungültig.');
        }
        if ($lon !== null && ($lon < -180 || $lon > 180)) {
            throw new \InvalidArgumentException('Längengrad ist ungültig.');
        }

        $id = Uuid::v4();
        $this->pdo->prepare(
            'INSERT INTO public_problem_areas
             (id, created_by_user_id, name, city, district, state,
              generalized_latitude, generalized_longitude, radius_m, status, moderation_status,
              created_at, updated_at)
             VALUES
             (:id, :user_id, :name, :city, :district, :state,
              :lat, :lon, :radius_m, "NEW", "PENDING", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([
            'id' => $id,
            'user_id' => $userId,
            'name' => $name,
            'city' => $this->shortNullable($input['city'] ?? null, 120),
            'district' => $this->shortNullable($input['district'] ?? null, 120),
            'state' => $this->shortNullable($input['state'] ?? null, 120),
            'lat' => $lat,
            'lon' => $lon,
            'radius_m' => max(100, min(5000, (int) ($input['radius_m'] ?? 300))),
        ]);

        return $this->publicProblemArea($id, true)
            ?? throw new \RuntimeException('Öffentliche Problemstelle konnte nicht geladen werden.');
    }

    public function publicProblemAreas(bool $includePendingOwned = false, ?string $userId = null): array
    {
        $where = 'moderation_status = "APPROVED"';

        if ($includePendingOwned && $userId !== null) {
            $where = '(' . $where . ' OR created_by_user_id = :user_id)';
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, created_by_user_id, name, city, district, state,
                    generalized_latitude, generalized_longitude, radius_m,
                    status, moderation_status, created_at
             FROM public_problem_areas
             WHERE ' . $where . '
             ORDER BY updated_at DESC'
        );
        $stmt->execute($includePendingOwned && $userId !== null ? ['user_id' => $userId] : []);

        return $stmt->fetchAll();
    }

    public function addPublicProblemObservation(
        string $userId,
        string $areaId,
        string $date,
        ?string $offenseCategory,
        ?string $note
    ): string {
        if (!$this->publicProblemVisible($areaId)) {
            throw new \DomainException('Öffentliche Problemstelle ist nicht freigegeben.');
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Beobachtungsdatum ist ungültig.');
        }

        $note = trim((string) $note);
        if (mb_strlen($note) > 1000) {
            throw new \InvalidArgumentException('Beobachtungsnotiz ist zu lang.');
        }

        $id = Uuid::v4();
        $this->pdo->prepare(
            'INSERT INTO public_problem_observations
             (id, problem_area_id, user_id, observation_date, offense_category, note, status, created_at)
             VALUES
             (:id, :area_id, :user_id, :observation_date, :offense_category, :note, "PUBLISHED", UTC_TIMESTAMP())'
        )->execute([
            'id' => $id,
            'area_id' => $areaId,
            'user_id' => $userId,
            'observation_date' => $date,
            'offense_category' => $this->shortNullable($offenseCategory, 100),
            'note' => $note === '' ? null : $note,
        ]);

        return $id;
    }

    public function publicProblemArea(string $areaId, bool $allowPending = false): ?array
    {
        $sql =
            'SELECT ppa.*, cp.username
             FROM public_problem_areas ppa
             LEFT JOIN community_profiles cp ON cp.user_id = ppa.created_by_user_id
             WHERE ppa.id = :id';

        if (!$allowPending) {
            $sql .= ' AND ppa.moderation_status = "APPROVED"';
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $areaId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $obs = $this->pdo->prepare(
            'SELECT observation_date, offense_category, note
             FROM public_problem_observations
             WHERE problem_area_id = :id AND status = "PUBLISHED"
             ORDER BY observation_date DESC, created_at DESC'
        );
        $obs->execute(['id' => $areaId]);
        $row['observations'] = $obs->fetchAll();

        return $row;
    }

    public function block(string $userId, string $blockedUserId): void
    {
        if ($userId === $blockedUserId) {
            throw new \InvalidArgumentException('Eigener Account kann nicht blockiert werden.');
        }

        $this->pdo->prepare(
            'INSERT IGNORE INTO community_blocks
             (blocker_user_id, blocked_user_id, created_at)
             VALUES (:blocker, :blocked, UTC_TIMESTAMP())'
        )->execute(['blocker' => $userId, 'blocked' => $blockedUserId]);
    }

    public function sendMessage(string $userId, string $recipientUserId, string $body): string
    {
        $this->authorization->authorize($userId, 'community.message.send');

        if ($userId === $recipientUserId) {
            throw new \InvalidArgumentException('Nachrichten an den eigenen Account sind nicht möglich.');
        }

        if ($this->profileByUser($userId, $recipientUserId) === null) {
            throw new \DomainException('Empfänger hat kein aktives Community-Profil.');
        }

        if ($this->blockedEitherDirection($userId, $recipientUserId)) {
            throw new \DomainException('Nachricht kann aufgrund einer Blockierung nicht gesendet werden.');
        }

        $body = trim($body);
        if (mb_strlen($body) < 1 || mb_strlen($body) > 10000) {
            throw new \InvalidArgumentException('Nachricht ist ungültig.');
        }

        $acceptedBefore = $this->pdo->prepare(
            'SELECT COUNT(*) FROM community_messages
             WHERE (
                    (sender_user_id = :user_a AND recipient_user_id = :recipient_a)
                 OR (sender_user_id = :recipient_b AND recipient_user_id = :user_b)
             )
               AND status IN ("ACCEPTED","READ")'
        );
        $acceptedBefore->execute([
            'user_a' => $userId,
            'recipient_a' => $recipientUserId,
            'recipient_b' => $recipientUserId,
            'user_b' => $userId,
        ]);

        $status = (int) $acceptedBefore->fetchColumn() > 0 ? 'ACCEPTED' : 'REQUEST';
        $id = Uuid::v4();

        $this->pdo->prepare(
            'INSERT INTO community_messages
             (id, sender_user_id, recipient_user_id, body_encrypted, body_sha256, status, created_at, read_at)
             VALUES
             (:id, :sender, :recipient, :body, :sha256, :status, UTC_TIMESTAMP(), NULL)'
        )->execute([
            'id' => $id,
            'sender' => $userId,
            'recipient' => $recipientUserId,
            'body' => $this->cipher->encrypt($body),
            'sha256' => hash('sha256', $body),
            'status' => $status,
        ]);

        return $id;
    }

    public function sendMessageToUsername(string $userId, string $username, string $body): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM community_profiles
             WHERE LOWER(username) = LOWER(:username) AND status = "ACTIVE" LIMIT 1'
        );
        $stmt->execute(['username' => trim($username)]);
        $recipient = $stmt->fetchColumn();

        if (!is_string($recipient) || $recipient === '') {
            throw new \DomainException('Community-Nutzer wurde nicht gefunden.');
        }

        return $this->sendMessage($userId, $recipient, $body);
    }

    public function acceptMessageRequest(string $userId, string $messageId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE community_messages
             SET status = "ACCEPTED"
             WHERE id = :id
               AND recipient_user_id = :user_id
               AND status = "REQUEST"'
        );
        $stmt->execute(['id' => $messageId, 'user_id' => $userId]);

        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Nachrichtenanfrage wurde nicht gefunden.');
        }
    }

    public function blockUsername(string $userId, string $username): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM community_profiles
             WHERE LOWER(username) = LOWER(:username) AND status = "ACTIVE" LIMIT 1'
        );
        $stmt->execute(['username' => trim($username)]);
        $blocked = $stmt->fetchColumn();

        if (!is_string($blocked) || $blocked === '') {
            throw new \DomainException('Community-Nutzer wurde nicht gefunden.');
        }

        $this->block($userId, $blocked);
    }

    public function inbox(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, sender.username AS sender_username, recipient.username AS recipient_username
             FROM community_messages m
             INNER JOIN community_profiles sender ON sender.user_id = m.sender_user_id
             INNER JOIN community_profiles recipient ON recipient.user_id = m.recipient_user_id
             WHERE (m.sender_user_id = :user_a OR m.recipient_user_id = :user_b)
             ORDER BY m.created_at DESC LIMIT 100'
        );
        $stmt->execute(['user_a' => $userId, 'user_b' => $userId]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $body = $this->cipher->decrypt((string) $row['body_encrypted']);
            if (!hash_equals((string) $row['body_sha256'], hash('sha256', $body))) {
                throw new \RuntimeException('Integrität einer Community-Nachricht ist verletzt.');
            }
            unset($row['body_encrypted']);
            $row['body'] = $body;
            $rows[] = $row;
        }

        return $rows;
    }

    private function visibleProfile(array $row, ?string $viewerUserId): array
    {
        $visibility = json_decode((string) $row['visibility_json'], true);
        $visibility = is_array($visibility) ? $visibility : [];

        $isOwner = $viewerUserId !== null && $viewerUserId === $row['user_id'];
        $isLoggedIn = $viewerUserId !== null;

        $result = [
            'user_id' => (string) $row['user_id'],
            'username' => (string) $row['username'],
            'leaderboard_opt_in' => (bool) $row['leaderboard_opt_in'],
            'created_at' => $row['created_at'],
        ];

        foreach (['bio','region_state','region_district','region_city'] as $field) {
            $scope = strtoupper((string) ($visibility[$field] ?? 'PRIVATE'));

            if ($isOwner || $scope === 'PUBLIC' || ($scope === 'LOGGED_IN' && $isLoggedIn)) {
                $result[$field] = $row[$field];
            }
        }

        return $result;
    }

    private function visibility(string $value): string
    {
        $value = strtoupper(trim($value));

        if (!in_array($value, ['PUBLIC','LOGGED_IN','PRIVATE'], true)) {
            return 'PRIVATE';
        }

        return $value;
    }

    private function group(string $groupId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM community_groups WHERE id = :id AND status = "ACTIVE" LIMIT 1'
        );
        $stmt->execute(['id' => $groupId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function isActiveMember(string $userId, string $groupId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM community_group_memberships
             WHERE group_id = :group_id AND user_id = :user_id AND status = "ACTIVE"'
        );
        $stmt->execute(['group_id' => $groupId, 'user_id' => $userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function publicProblemVisible(string $id): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM public_problem_areas
             WHERE id = :id AND moderation_status = "APPROVED"'
        );
        $stmt->execute(['id' => $id]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function ownedPublishedRelease(string $userId, string $releaseId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM community_case_releases
             WHERE id = :id AND user_id = :user_id AND status = "PUBLISHED"'
        );
        $stmt->execute(['id' => $releaseId, 'user_id' => $userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function blockedEitherDirection(string $userA, string $userB): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM community_blocks
             WHERE (blocker_user_id = :a1 AND blocked_user_id = :b1)
                OR (blocker_user_id = :b2 AND blocked_user_id = :a2)'
        );
        $stmt->execute([
            'a1' => $userA,
            'b1' => $userB,
            'b2' => $userB,
            'a2' => $userA,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function uniqueSlug(string $name): string
    {
        $base = mb_strtolower($name, 'UTF-8');
        $base = preg_replace('/[^a-z0-9]+/u', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base) ?? '';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'gruppe';
        }

        for ($i = 0; $i < 20; $i++) {
            $slug = $base . ($i === 0 ? '' : '-' . ($i + 1));
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM community_groups WHERE slug = :slug');
            $stmt->execute(['slug' => $slug]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }
        }

        return $base . '-' . substr(str_replace('-', '', Uuid::v4()), 0, 8);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function shortNullable(mixed $value, int $length): ?string
    {
        $value = $this->nullable($value);

        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
