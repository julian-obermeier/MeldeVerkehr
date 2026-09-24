<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth;

final class AuthManager
{
    private const SESSION_KEY = 'auth_user_id';
    private const PENDING_SECOND_FACTOR_KEY = 'pending_second_factor_user_id';
    private const AUTHENTICATED_AT_KEY = 'authenticated_at';

    public function id(): ?string
    {
        $id = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function check(): bool
    {
        return $this->id() !== null;
    }

    public function login(string $userId): void
    {
        session_regenerate_id(true);
        unset($_SESSION[self::PENDING_SECOND_FACTOR_KEY]);
        $_SESSION[self::SESSION_KEY] = $userId;
        $_SESSION[self::AUTHENTICATED_AT_KEY] = time();
    }

    public function beginSecondFactor(string $userId): void
    {
        session_regenerate_id(true);
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::AUTHENTICATED_AT_KEY]);
        $_SESSION[self::PENDING_SECOND_FACTOR_KEY] = $userId;
    }

    public function pendingSecondFactorId(): ?string
    {
        $id = $_SESSION[self::PENDING_SECOND_FACTOR_KEY] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function completeSecondFactor(): ?string
    {
        $userId = $this->pendingSecondFactorId();

        if ($userId === null) {
            return null;
        }

        $this->login($userId);

        return $userId;
    }

    public function recentlyAuthenticated(int $seconds = 600): bool
    {
        $authenticatedAt = $_SESSION[self::AUTHENTICATED_AT_KEY] ?? null;

        return $this->check()
            && is_int($authenticatedAt)
            && $authenticatedAt >= time() - max(1, $seconds);
    }

    public function logout(): void
    {
        unset(
            $_SESSION[self::SESSION_KEY],
            $_SESSION[self::PENDING_SECOND_FACTOR_KEY],
            $_SESSION[self::AUTHENTICATED_AT_KEY]
        );
        session_regenerate_id(true);
    }
}
