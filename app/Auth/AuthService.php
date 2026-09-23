<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth;

use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class AuthService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function register(array $data): array
    {
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));

        if ($firstName === '' || $lastName === '') {
            throw new \InvalidArgumentException('Vor- und Nachname sind erforderlich.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Bitte eine gültige E-Mail-Adresse angeben.');
        }

        if (strlen($password) < 12) {
            throw new \InvalidArgumentException('Das Passwort muss mindestens 12 Zeichen lang sein.');
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $exists->execute(['email' => $email]);

        if ($exists->fetchColumn() !== false) {
            throw new \DomainException('Für diese E-Mail-Adresse existiert bereits ein Konto.');
        }

        $id = Uuid::v4();

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(
                'INSERT INTO users (
                    id, first_name, last_name, email, password_hash, email_verified_at, status, created_at, updated_at
                 ) VALUES (
                    :id, :first_name, :last_name, :email, :password_hash, NULL, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                 )'
            );
            $stmt->execute([
                'id' => $id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'status' => 'ACTIVE',
            ]);

            $role = $this->pdo->query("SELECT id FROM roles WHERE name = 'USER' LIMIT 1")->fetchColumn();
            if ($role === false) {
                throw new \RuntimeException('USER role is not configured.');
            }

            $assign = $this->pdo->prepare(
                'INSERT INTO user_roles (user_id, role_id, created_at) VALUES (:user_id, :role_id, UTC_TIMESTAMP())'
            );
            $assign->execute(['user_id' => $id, 'role_id' => (int) $role]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return $this->findById($id) ?? throw new \RuntimeException('User creation failed.');
    }

    public function authenticate(string $email, string $password): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, first_name, last_name, email, password_hash, email_verified_at, status
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
        $user = $stmt->fetch();

        if (!is_array($user) || $user['status'] !== 'ACTIVE' || !password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $this->pdo->prepare(
                'UPDATE users SET password_hash = :hash, updated_at = UTC_TIMESTAMP() WHERE id = :id'
            );
            $rehash->execute([
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $user['id'],
            ]);
        }

        unset($user['password_hash']);

        return $user;
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, first_name, last_name, email, email_verified_at, status
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return is_array($user) ? $user : null;
    }
}
