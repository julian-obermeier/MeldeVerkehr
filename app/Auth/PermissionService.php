<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth;

use PDO;

final class PermissionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function hasRole(string $userId, string $role): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id AND r.name = :role
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'role' => $role]);

        return $stmt->fetchColumn() !== false;
    }

    public function can(string $userId, string $permission): bool
    {
        if ($this->hasRole($userId, 'SUPER_ADMIN')) {
            return true;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM user_roles ur
             INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :user_id AND p.name = :permission
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'permission' => $permission,
        ]);

        return $stmt->fetchColumn() !== false;
    }
}
