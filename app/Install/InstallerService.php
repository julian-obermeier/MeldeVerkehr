<?php

declare(strict_types=1);

namespace MeldeVerkehr\Install;

use MeldeVerkehr\Database\Connection;
use MeldeVerkehr\Database\MigrationRunner;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class InstallerService
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockPath());
    }

    public function testDatabase(array $database): array
    {
        return Connection::test($database);
    }

    public function install(array $app, array $database, array $admin): array
    {
        if ($this->isInstalled()) {
            throw new \RuntimeException('MeldeVerkehr is already installed.');
        }

        $pdo = Connection::make($database);
        $runner = new MigrationRunner($pdo, $this->basePath . '/database/migrations');
        $migrations = $runner->migrate();

        try {
            $pdo->beginTransaction();
            $this->seedAccess($pdo);
            $userId = $this->createOrUpdateSuperAdmin($pdo, $admin);
            $this->saveSettings($pdo, $app);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        $appKey = 'base64:' . base64_encode(random_bytes(32));

        (new EnvWriter())->write($this->basePath . '/.env', [
            'APP_NAME' => $app['name'],
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $app['url'],
            'APP_KEY' => $appKey,
            'APP_TIMEZONE' => $app['timezone'],
            'APP_LOCALE' => 'de',
            'APP_INSTALLED' => 'true',
            'DB_HOST' => $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => $database['database'],
            'DB_USERNAME' => $database['username'],
            'DB_PASSWORD' => $database['password'],
            'MAIL_HOST' => '',
            'MAIL_PORT' => '587',
            'MAIL_USERNAME' => '',
            'MAIL_PASSWORD' => '',
            'MAIL_ENCRYPTION' => 'tls',
            'MAIL_FROM_ADDRESS' => strtolower(trim((string) $admin['email'])),
            'MAIL_FROM_NAME' => $app['name'],
            'IMAP_HOST' => '',
            'IMAP_PORT' => '993',
            'IMAP_USERNAME' => '',
            'IMAP_PASSWORD' => '',
            'IMAP_ENCRYPTION' => 'ssl',
            'AI_PROVIDER' => '',
            'AI_API_KEY' => '',
            'AI_MODEL' => '',
            'OCR_PROVIDER' => '',
            'OCR_API_KEY' => '',
            'PUSH_VAPID_PUBLIC_KEY' => '',
            'PUSH_VAPID_PRIVATE_KEY' => '',
        ]);

        $directory = dirname($this->lockPath());

        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create installer lock directory.');
        }

        $lock = json_encode([
            'installed_at' => gmdate(DATE_ATOM),
            'version' => trim((string) @file_get_contents($this->basePath . '/VERSION')),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($this->lockPath(), $lock . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Could not create installation lock.');
        }

        return [
            'migrations' => $migrations,
            'admin_user_id' => $userId,
        ];
    }

    private function seedAccess(PDO $pdo): void
    {
        foreach (['USER', 'COMMUNITY_MODERATOR', 'REGIONAL_MODERATOR', 'ADMIN', 'SUPER_ADMIN', 'AUTHORITY_USER', 'AUTHORITY_ADMIN'] as $role) {
            $stmt = $pdo->prepare(
                'INSERT INTO roles (name, created_at) VALUES (:name, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE name = VALUES(name)'
            );
            $stmt->execute(['name' => $role]);
        }
    }

    private function createOrUpdateSuperAdmin(PDO $pdo, array $admin): string
    {
        $email = strtolower(trim((string) $admin['email']));
        $password = (string) $admin['password'];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid administrator email address is required.');
        }

        if (strlen($password) < 12) {
            throw new \InvalidArgumentException('Administrator password must contain at least 12 characters.');
        }

        $find = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $find->execute(['email' => $email]);
        $existingId = $find->fetchColumn();

        $id = is_string($existingId) && $existingId !== '' ? $existingId : Uuid::v4();
        $hash = password_hash($password, PASSWORD_DEFAULT);

        if ($existingId !== false) {
            $stmt = $pdo->prepare(
                'UPDATE users
                 SET first_name = :first_name,
                     last_name = :last_name,
                     password_hash = :password_hash,
                     email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()),
                     status = :status,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO users (
                    id, first_name, last_name, email, password_hash, email_verified_at, status, created_at, updated_at
                 ) VALUES (
                    :id, :first_name, :last_name, :email, :password_hash, UTC_TIMESTAMP(), :status, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                 )'
            );
        }

        $params = [
            'id' => $id,
            'first_name' => trim((string) $admin['first_name']),
            'last_name' => trim((string) $admin['last_name']),
            'password_hash' => $hash,
            'status' => 'ACTIVE',
        ];

        if ($existingId === false) {
            $params['email'] = $email;
        }

        $stmt->execute($params);

        $roleId = $pdo->query("SELECT id FROM roles WHERE name = 'SUPER_ADMIN' LIMIT 1")->fetchColumn();

        if ($roleId === false) {
            throw new \RuntimeException('SUPER_ADMIN role was not created.');
        }

        $assign = $pdo->prepare(
            'INSERT INTO user_roles (user_id, role_id, created_at)
             VALUES (:user_id, :role_id, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)'
        );
        $assign->execute([
            'user_id' => $id,
            'role_id' => (int) $roleId,
        ]);

        return $id;
    }

    private function saveSettings(PDO $pdo, array $app): void
    {
        $settings = [
            'app.name' => (string) $app['name'],
            'app.url' => (string) $app['url'],
            'app.timezone' => (string) $app['timezone'],
            'app.locale' => 'de',
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at)
             VALUES (:setting_key, :setting_value, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = UTC_TIMESTAMP()'
        );

        foreach ($settings as $key => $value) {
            $stmt->execute([
                'setting_key' => $key,
                'setting_value' => $value,
            ]);
        }
    }

    private function lockPath(): string
    {
        return $this->basePath . '/storage/app/installed.lock';
    }
}
