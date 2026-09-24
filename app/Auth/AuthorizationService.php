<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth;

final class AuthorizationService
{
    public function __construct(private readonly PermissionService $permissions)
    {
    }

    public function can(
        string $userId,
        string $permission,
        ?string $ownerUserId = null,
        ?callable $scopeCheck = null
    ): bool {
        if (!$this->permissions->can($userId, $permission)) {
            return false;
        }

        if (
            $ownerUserId !== null
            && $ownerUserId !== $userId
            && !$this->permissions->hasRole($userId, 'SUPER_ADMIN')
        ) {
            return false;
        }

        if ($scopeCheck !== null && !$scopeCheck($userId)) {
            return false;
        }

        return true;
    }

    public function authorize(
        string $userId,
        string $permission,
        ?string $ownerUserId = null,
        ?callable $scopeCheck = null
    ): void {
        if (!$this->can($userId, $permission, $ownerUserId, $scopeCheck)) {
            throw new AuthorizationException('Access denied.');
        }
    }
}
