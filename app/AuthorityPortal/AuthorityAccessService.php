<?php

declare(strict_types=1);

namespace MeldeVerkehr\AuthorityPortal;

use MeldeVerkehr\Auth\AuthorizationException;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Support\Uuid;
use PDO;

final class AuthorityAccessService
{
    private const SCOPE_PERMISSIONS = [
        'AUTHORITY_USER' => [
            'authority.case.view',
            'authority.case.reply',
            'authority.case.export',
        ],
        'AUTHORITY_ADMIN' => [
            'authority.case.view',
            'authority.case.reply',
            'authority.case.export',
            'authority.holder.read',
            'authority.holder.write',
            'authority.users.manage',
            'authority.tokens.manage',
        ],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly PermissionService $permissions
    ) {
    }

    public function scopes(string $userId): array
    {
        if ($this->permissions->hasRole($userId, 'SUPER_ADMIN')) {
            return $this->pdo->query(
                'SELECT a.id AS authority_id, a.name AS authority_name,
                        "AUTHORITY_ADMIN" AS scope_role, "ACTIVE" AS status
                 FROM authorities a
                 WHERE a.status <> "DISABLED"
                 ORDER BY a.name'
            )->fetchAll();
        }

        $stmt = $this->pdo->prepare(
            'SELECT aus.authority_id, a.name AS authority_name, aus.scope_role, aus.status
             FROM authority_user_scopes aus
             INNER JOIN authorities a ON a.id = aus.authority_id
             WHERE aus.user_id = :user_id
               AND aus.status = "ACTIVE"
             ORDER BY a.name'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function assertAuthority(
        string $userId,
        string $authorityId,
        string $permission
    ): array {
        if ($this->permissions->hasRole($userId, 'SUPER_ADMIN')) {
            $row = $this->authority($authorityId);
            if ($row === null) {
                throw new AuthorizationException('Access denied.');
            }

            return [
                'authority_id' => $authorityId,
                'authority_name' => $row['name'],
                'scope_role' => 'AUTHORITY_ADMIN',
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT aus.authority_id, a.name AS authority_name, aus.scope_role
             FROM authority_user_scopes aus
             INNER JOIN authorities a ON a.id = aus.authority_id
             WHERE aus.user_id = :user_id
               AND aus.authority_id = :authority_id
               AND aus.status = "ACTIVE"
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'authority_id' => $authorityId,
        ]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new AuthorizationException('Access denied.');
        }

        $scopeRole = (string) $row['scope_role'];
        $allowed = self::SCOPE_PERMISSIONS[$scopeRole] ?? [];

        if (
            !in_array($permission, $allowed, true)
            || !$this->permissions->can($userId, $permission)
        ) {
            throw new AuthorizationException('Access denied.');
        }

        return $row;
    }

    public function assertAuthorityAdmin(string $userId, string $authorityId): array
    {
        $scope = $this->assertAuthority($userId, $authorityId, 'authority.users.manage');

        if (
            !$this->permissions->hasRole($userId, 'SUPER_ADMIN')
            && (string) $scope['scope_role'] !== 'AUTHORITY_ADMIN'
        ) {
            throw new AuthorizationException('Access denied.');
        }

        return $scope;
    }

    public function assertCaseForAuthority(
        string $userId,
        string $authorityId,
        string $caseId,
        string $permission = 'authority.case.view'
    ): array {
        $this->assertAuthority($userId, $authorityId, $permission);

        $stmt = $this->pdo->prepare(
            'SELECT d.authority_id, a.name AS authority_name, d.id AS dispatch_id,
                    d.dispatch_package_id, d.sent_at
             FROM dispatches d
             INNER JOIN authorities a ON a.id = d.authority_id
             WHERE d.case_id = :case_id
               AND d.authority_id = :authority_id
               AND d.status = "SENT"
             ORDER BY d.sent_at DESC, d.created_at DESC
             LIMIT 1'
        );
        $stmt->execute([
            'case_id' => $caseId,
            'authority_id' => $authorityId,
        ]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new AuthorizationException('Access denied.');
        }

        return $row;
    }

    public function assertCase(
        string $userId,
        string $caseId,
        string $permission = 'authority.case.view'
    ): array {
        if (!$this->permissions->can($userId, $permission)) {
            throw new AuthorizationException('Access denied.');
        }

        $authorityIds = array_map(
            static fn(array $row): string => (string) $row['authority_id'],
            $this->scopes($userId)
        );

        if ($authorityIds === []) {
            throw new AuthorizationException('Access denied.');
        }

        $placeholders = implode(',', array_fill(0, count($authorityIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT d.authority_id, a.name AS authority_name, d.id AS dispatch_id,
                    d.dispatch_package_id, d.sent_at
             FROM dispatches d
             INNER JOIN authorities a ON a.id = d.authority_id
             WHERE d.case_id = ?
               AND d.status = "SENT"
               AND d.authority_id IN (' . $placeholders . ')
             ORDER BY d.sent_at DESC, d.created_at DESC
             LIMIT 1'
        );
        $stmt->execute(array_merge([$caseId], $authorityIds));
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new AuthorizationException('Access denied.');
        }

        return $row;
    }

    public function assignScope(
        string $actorUserId,
        string $targetUserId,
        string $authorityId,
        string $scopeRole
    ): array {
        $this->assertAuthorityAdmin($actorUserId, $authorityId);

        $scopeRole = strtoupper(trim($scopeRole));
        if (!in_array($scopeRole, ['AUTHORITY_USER','AUTHORITY_ADMIN'], true)) {
            throw new \InvalidArgumentException('Ungültige Authority-Rolle.');
        }

        $roleName = $scopeRole;
        $roleStmt = $this->pdo->prepare(
            'SELECT id FROM roles WHERE name = :name LIMIT 1'
        );
        $roleStmt->execute(['name' => $roleName]);
        $roleId = $roleStmt->fetchColumn();

        if (!is_string($roleId) || $roleId === '') {
            throw new \RuntimeException('Authority-Rolle fehlt.');
        }

        $idStmt = $this->pdo->prepare(
            'SELECT id FROM authority_user_scopes
             WHERE user_id = :user_id AND authority_id = :authority_id LIMIT 1'
        );
        $idStmt->execute([
            'user_id' => $targetUserId,
            'authority_id' => $authorityId,
        ]);
        $existing = $idStmt->fetchColumn();
        $id = is_string($existing) && $existing !== '' ? $existing : Uuid::v4();

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare(
                'INSERT INTO authority_user_scopes
                 (id, user_id, authority_id, scope_role, status, created_by_user_id, created_at, updated_at)
                 VALUES
                 (:id, :user_id, :authority_id, :scope_role, "ACTIVE", :actor, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    scope_role = VALUES(scope_role),
                    status = "ACTIVE",
                    updated_at = UTC_TIMESTAMP()'
            )->execute([
                'id' => $id,
                'user_id' => $targetUserId,
                'authority_id' => $authorityId,
                'scope_role' => $scopeRole,
                'actor' => $actorUserId,
            ]);

            $this->pdo->prepare(
                'INSERT IGNORE INTO user_roles (user_id, role_id, created_at)
                 VALUES (:user_id, :role_id, UTC_TIMESTAMP())'
            )->execute([
                'user_id' => $targetUserId,
                'role_id' => $roleId,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'id' => $id,
            'user_id' => $targetUserId,
            'authority_id' => $authorityId,
            'scope_role' => $scopeRole,
            'status' => 'ACTIVE',
        ];
    }

    public function users(string $actorUserId, string $authorityId): array
    {
        $this->assertAuthorityAdmin($actorUserId, $authorityId);

        $stmt = $this->pdo->prepare(
            'SELECT aus.user_id, aus.scope_role, aus.status, aus.created_at,
                    u.first_name, u.last_name, u.email
             FROM authority_user_scopes aus
             INNER JOIN users u ON u.id = aus.user_id
             WHERE aus.authority_id = :authority_id
             ORDER BY u.last_name, u.first_name'
        );
        $stmt->execute(['authority_id' => $authorityId]);

        return $stmt->fetchAll();
    }

    private function authority(string $authorityId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name FROM authorities WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $authorityId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
