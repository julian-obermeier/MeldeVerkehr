<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth;

final class AuthManager
{
    private const SESSION_KEY = 'auth_user_id';

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
        $_SESSION[self::SESSION_KEY] = $userId;
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }
}
