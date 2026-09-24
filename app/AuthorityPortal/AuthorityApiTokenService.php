<?php

declare(strict_types=1);

namespace MeldeVerkehr\AuthorityPortal;

use MeldeVerkehr\Auth\AuthorizationException;
use MeldeVerkehr\Support\Uuid;
use PDO;

final class AuthorityApiTokenService
{
    private const ALLOWED_SCOPES = [
        'cases:read' => 'authority.case.view',
        'inquiries:write' => 'authority.case.reply',
        'exports:read' => 'authority.case.export',
        'holder:read' => 'authority.holder.read',
        'holder:write' => 'authority.holder.write',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorityAccessService $access
    ) {
    }

    public function create(
        string $actorUserId,
        string $authorityId,
        string $name,
        array $scopes,
        ?\DateTimeImmutable $expiresAt = null
    ): array {
        $this->access->assertAuthorityAdmin($actorUserId, $authorityId);

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Tokenname ist ungültig.');
        }

        $scopes = array_values(array_unique(array_map(
            static fn(mixed $scope): string => trim((string) $scope),
            $scopes
        )));

        if ($scopes === []) {
            throw new \InvalidArgumentException('Mindestens ein API-Scope ist erforderlich.');
        }

        foreach ($scopes as $scope) {
            if (!array_key_exists($scope, self::ALLOWED_SCOPES)) {
                throw new \InvalidArgumentException('Unbekannter API-Scope: ' . $scope);
            }

            $this->access->assertAuthority(
                $actorUserId,
                $authorityId,
                self::ALLOWED_SCOPES[$scope]
            );
        }

        if ($expiresAt !== null && $expiresAt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            throw new \InvalidArgumentException('Token-Ablauf muss in der Zukunft liegen.');
        }

        $raw = 'mvapi_' . rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
        $id = Uuid::v4();
        $prefix = substr($raw, 0, 18);

        $this->pdo->prepare(
            'INSERT INTO authority_api_tokens
             (id, authority_id, user_id, name, token_prefix, token_hash, scopes_json,
              status, expires_at, last_used_at, created_at, revoked_at)
             VALUES
             (:id, :authority_id, :user_id, :name, :prefix, :hash, :scopes,
              "ACTIVE", :expires_at, NULL, UTC_TIMESTAMP(), NULL)'
        )->execute([
            'id' => $id,
            'authority_id' => $authorityId,
            'user_id' => $actorUserId,
            'name' => $name,
            'prefix' => $prefix,
            'hash' => hash('sha256', $raw),
            'scopes' => json_encode(
                $scopes,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            'expires_at' => $expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);

        return [
            'id' => $id,
            'authority_id' => $authorityId,
            'name' => $name,
            'token' => $raw,
            'token_prefix' => $prefix,
            'scopes' => $scopes,
            'expires_at' => $expiresAt?->format(DATE_ATOM),
        ];
    }

    public function authenticate(string $authorizationHeader, string $requiredScope): array
    {
        if (!array_key_exists($requiredScope, self::ALLOWED_SCOPES)) {
            throw new \InvalidArgumentException('Ungültiger API-Scope.');
        }

        if (preg_match('/^Bearer\s+(.+)$/i', trim($authorizationHeader), $match) !== 1) {
            throw new AuthorizationException('Invalid bearer token.');
        }

        $raw = trim((string) $match[1]);
        if (!str_starts_with($raw, 'mvapi_') || strlen($raw) < 30) {
            throw new AuthorizationException('Invalid bearer token.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT t.*, u.status AS user_status
             FROM authority_api_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :hash
               AND t.status = "ACTIVE"
               AND t.revoked_at IS NULL
               AND (t.expires_at IS NULL OR t.expires_at > UTC_TIMESTAMP())
             LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $raw)]);
        $row = $stmt->fetch();

        if (!is_array($row) || (string) $row['user_status'] !== 'ACTIVE') {
            throw new AuthorizationException('Invalid bearer token.');
        }

        $scopes = json_decode((string) $row['scopes_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($scopes) || !in_array($requiredScope, $scopes, true)) {
            throw new AuthorizationException('Token scope denied.');
        }

        $this->access->assertAuthority(
            (string) $row['user_id'],
            (string) $row['authority_id'],
            self::ALLOWED_SCOPES[$requiredScope]
        );

        $this->pdo->prepare(
            'UPDATE authority_api_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = :id'
        )->execute(['id' => $row['id']]);

        return [
            'token_id' => (string) $row['id'],
            'user_id' => (string) $row['user_id'],
            'authority_id' => (string) $row['authority_id'],
            'scopes' => array_values($scopes),
        ];
    }

    public function list(string $actorUserId, string $authorityId): array
    {
        $this->access->assertAuthorityAdmin($actorUserId, $authorityId);

        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, name, token_prefix, scopes_json, status,
                    expires_at, last_used_at, created_at, revoked_at
             FROM authority_api_tokens
             WHERE authority_id = :authority_id
             ORDER BY created_at DESC'
        );
        $stmt->execute(['authority_id' => $authorityId]);

        return array_map(static function (array $row): array {
            $scopes = json_decode((string) $row['scopes_json'], true);
            $row['scopes'] = is_array($scopes) ? $scopes : [];
            unset($row['scopes_json']);

            return $row;
        }, $stmt->fetchAll());
    }

    public function revoke(string $actorUserId, string $authorityId, string $tokenId): void
    {
        $this->access->assertAuthorityAdmin($actorUserId, $authorityId);

        $stmt = $this->pdo->prepare(
            'UPDATE authority_api_tokens
             SET status = "REVOKED", revoked_at = UTC_TIMESTAMP()
             WHERE id = :id AND authority_id = :authority_id AND status = "ACTIVE"'
        );
        $stmt->execute([
            'id' => $tokenId,
            'authority_id' => $authorityId,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('API-Token nicht gefunden oder bereits widerrufen.');
        }
    }
}
