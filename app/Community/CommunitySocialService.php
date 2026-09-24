<?php

declare(strict_types=1);

namespace MeldeVerkehr\Community;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Uuid;
use PDO;

final class CommunitySocialService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization,
        private readonly SecretCipher $cipher,
        private readonly AuditLogger $audit,
        private readonly CommunityAbuseService $abuse
    ) {
    }

    public function profileStats(?string $viewerUserId, string $targetUserId): array
    {
        $followers = $this->count(
            'SELECT COUNT(*) FROM community_follows WHERE followed_user_id = :id',
            ['id' => $targetUserId]
        );
        $following = $this->count(
            'SELECT COUNT(*) FROM community_follows WHERE follower_user_id = :id',
            ['id' => $targetUserId]
        );

        $isFollowing = false;
        if ($viewerUserId !== null && $viewerUserId !== $targetUserId) {
            $isFollowing = $this->count(
                'SELECT COUNT(*) FROM community_follows
                 WHERE follower_user_id = :viewer AND followed_user_id = :target',
                ['viewer' => $viewerUserId, 'target' => $targetUserId]
            ) > 0;
        }

        return [
            'followers' => $followers,
            'following' => $following,
            'is_following' => $isFollowing,
        ];
    }

    public function followUsername(string $userId, string $username): void
    {
        $target = $this->userIdByUsername($username);
        if ($target === null) {
            throw new \DomainException('Community-Nutzer wurde nicht gefunden.');
        }
        if ($target === $userId) {
            throw new \InvalidArgumentException('Dem eigenen Profil kann nicht gefolgt werden.');
        }
        if ($this->blockedEitherDirection($userId, $target)) {
            throw new \DomainException('Folgen ist aufgrund einer Blockierung nicht möglich.');
        }

        $this->pdo->prepare(
            'INSERT IGNORE INTO community_follows
             (follower_user_id, followed_user_id, created_at)
             VALUES (:follower, :followed, UTC_TIMESTAMP())'
        )->execute(['follower' => $userId, 'followed' => $target]);

        $this->audit->log('COMMUNITY_USER_FOLLOWED', 'community_profile', $target, 'USER', $userId);
    }

    public function unfollowUsername(string $userId, string $username): void
    {
        $target = $this->userIdByUsername($username);
        if ($target === null) {
            throw new \DomainException('Community-Nutzer wurde nicht gefunden.');
        }

        $this->pdo->prepare(
            'DELETE FROM community_follows
             WHERE follower_user_id = :follower AND followed_user_id = :followed'
        )->execute(['follower' => $userId, 'followed' => $target]);

        $this->audit->log('COMMUNITY_USER_UNFOLLOWED', 'community_profile', $target, 'USER', $userId);
    }

    public function network(string $userId): array
    {
        $following = $this->pdo->prepare(
            'SELECT cp.user_id, cp.username, f.created_at
             FROM community_follows f
             INNER JOIN community_profiles cp ON cp.user_id = f.followed_user_id AND cp.status = "ACTIVE"
             WHERE f.follower_user_id = :user_id
             ORDER BY cp.username'
        );
        $following->execute(['user_id' => $userId]);

        $followers = $this->pdo->prepare(
            'SELECT cp.user_id, cp.username, f.created_at
             FROM community_follows f
             INNER JOIN community_profiles cp ON cp.user_id = f.follower_user_id AND cp.status = "ACTIVE"
             WHERE f.followed_user_id = :user_id
             ORDER BY cp.username'
        );
        $followers->execute(['user_id' => $userId]);

        return [
            'following' => array_values(array_filter(
                $following->fetchAll(),
                fn(array $row): bool => !$this->blockedEitherDirection($userId, (string) $row['user_id'])
            )),
            'followers' => array_values(array_filter(
                $followers->fetchAll(),
                fn(array $row): bool => !$this->blockedEitherDirection($userId, (string) $row['user_id'])
            )),
        ];
    }

    public function favorite(string $userId, string $postId): void
    {
        $this->assertPostAccessible($userId, $postId);

        $this->pdo->prepare(
            'INSERT IGNORE INTO community_favorites (user_id, post_id, created_at)
             VALUES (:user_id, :post_id, UTC_TIMESTAMP())'
        )->execute(['user_id' => $userId, 'post_id' => $postId]);

        $this->audit->log('COMMUNITY_POST_FAVORITED', 'community_post', $postId, 'USER', $userId);
    }

    public function unfavorite(string $userId, string $postId): void
    {
        $this->pdo->prepare(
            'DELETE FROM community_favorites WHERE user_id = :user_id AND post_id = :post_id'
        )->execute(['user_id' => $userId, 'post_id' => $postId]);

        $this->audit->log('COMMUNITY_POST_UNFAVORITED', 'community_post', $postId, 'USER', $userId);
    }

    public function isFavorite(string $userId, string $postId): bool
    {
        return $this->count(
            'SELECT COUNT(*) FROM community_favorites WHERE user_id = :user_id AND post_id = :post_id',
            ['user_id' => $userId, 'post_id' => $postId]
        ) > 0;
    }

    public function favoritePostIds(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT post_id FROM community_favorites WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function favorites(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.author_user_id, p.group_id, p.topic, p.body, p.created_at,
                    cp.username, g.name AS group_name, f.created_at AS favorited_at
             FROM community_favorites f
             INNER JOIN community_posts p ON p.id = f.post_id AND p.status = "PUBLISHED"
             INNER JOIN community_profiles cp ON cp.user_id = p.author_user_id AND cp.status = "ACTIVE"
             LEFT JOIN community_groups g ON g.id = p.group_id
             WHERE f.user_id = :user_id
             ORDER BY f.created_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return array_values(array_filter(
            $stmt->fetchAll(),
            fn(array $row): bool => !$this->blockedEitherDirection($userId, (string) $row['author_user_id'])
        ));
    }

    public function groupChat(string $userId, string $groupId): array
    {
        $group = $this->activeGroup($groupId);
        if ($group === null || !$this->isActiveMember($userId, $groupId)) {
            throw new \DomainException('Gruppenchat ist nur für aktive Gruppenmitglieder verfügbar.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT gm.id, gm.sender_user_id, gm.body_encrypted, gm.body_sha256, gm.created_at,
                    cp.username
             FROM community_group_messages gm
             INNER JOIN community_profiles cp ON cp.user_id = gm.sender_user_id AND cp.status = "ACTIVE"
             WHERE gm.group_id = :group_id AND gm.status = "PUBLISHED"
             ORDER BY gm.created_at DESC
             LIMIT 100'
        );
        $stmt->execute(['group_id' => $groupId]);

        $messages = [];
        foreach (array_reverse($stmt->fetchAll()) as $row) {
            if ($this->blockedEitherDirection($userId, (string) $row['sender_user_id'])) {
                continue;
            }

            $body = $this->cipher->decrypt((string) $row['body_encrypted']);
            if (!hash_equals((string) $row['body_sha256'], hash('sha256', $body))) {
                throw new \RuntimeException('Integrität einer Gruppennachricht ist verletzt.');
            }

            unset($row['body_encrypted']);
            $row['body'] = $body;
            $messages[] = $row;
        }

        return ['group' => $group, 'messages' => $messages];
    }

    public function sendGroupMessage(string $userId, string $groupId, string $body): string
    {
        $this->authorization->authorize($userId, 'community.message.send');

        if ($this->activeGroup($groupId) === null || !$this->isActiveMember($userId, $groupId)) {
            throw new \DomainException('Gruppenchat ist nur für aktive Gruppenmitglieder verfügbar.');
        }

        $body = trim($body);
        if (mb_strlen($body) < 1 || mb_strlen($body) > 10000) {
            throw new \InvalidArgumentException('Gruppennachricht ist ungültig.');
        }

        $this->abuse->assertAllowed($userId, 'MESSAGE', $body);

        $id = Uuid::v4();
        $this->pdo->prepare(
            'INSERT INTO community_group_messages
             (id, group_id, sender_user_id, body_encrypted, body_sha256, status, created_at)
             VALUES (:id, :group_id, :user_id, :body, :sha256, "PUBLISHED", UTC_TIMESTAMP())'
        )->execute([
            'id' => $id,
            'group_id' => $groupId,
            'user_id' => $userId,
            'body' => $this->cipher->encrypt($body),
            'sha256' => hash('sha256', $body),
        ]);

        $this->abuse->record($userId, 'MESSAGE', 'GROUP_MESSAGE', $id, $body, ['group_id' => $groupId]);
        $this->audit->log('COMMUNITY_GROUP_MESSAGE_SENT', 'community_group', $groupId, 'USER', $userId, [
            'message_id' => $id,
        ]);

        return $id;
    }

    private function assertPostAccessible(string $userId, string $postId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.author_user_id
             FROM community_posts p
             INNER JOIN community_profiles cp ON cp.user_id = p.author_user_id AND cp.status = "ACTIVE"
             WHERE p.id = :id AND p.status = "PUBLISHED" LIMIT 1'
        );
        $stmt->execute(['id' => $postId]);
        $author = $stmt->fetchColumn();

        if (!is_string($author) || $author === '' || $this->blockedEitherDirection($userId, $author)) {
            throw new \DomainException('Beitrag nicht gefunden oder nicht zugänglich.');
        }
    }

    private function userIdByUsername(string $username): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM community_profiles
             WHERE LOWER(username) = LOWER(:username) AND status = "ACTIVE" LIMIT 1'
        );
        $stmt->execute(['username' => trim($username)]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function activeGroup(string $groupId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, slug, description, group_type
             FROM community_groups WHERE id = :id AND status = "ACTIVE" LIMIT 1'
        );
        $stmt->execute(['id' => $groupId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function isActiveMember(string $userId, string $groupId): bool
    {
        return $this->count(
            'SELECT COUNT(*) FROM community_group_memberships
             WHERE group_id = :group_id AND user_id = :user_id AND status = "ACTIVE"',
            ['group_id' => $groupId, 'user_id' => $userId]
        ) > 0;
    }

    private function blockedEitherDirection(string $userA, string $userB): bool
    {
        return $this->count(
            'SELECT COUNT(*) FROM community_blocks
             WHERE (blocker_user_id = :a1 AND blocked_user_id = :b1)
                OR (blocker_user_id = :b2 AND blocked_user_id = :a2)',
            ['a1' => $userA, 'b1' => $userB, 'b2' => $userB, 'a2' => $userA]
        ) > 0;
    }

    private function count(string $sql, array $params): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
