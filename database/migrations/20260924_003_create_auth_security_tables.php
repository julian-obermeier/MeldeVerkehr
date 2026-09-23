<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_003_create_auth_security_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_verification_tokens (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_email_verification_user (user_id),
                INDEX idx_email_verification_expiry (expires_at),
                CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_password_reset_user (user_id),
                INDEX idx_password_reset_expiry (expires_at),
                CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS auth_rate_limits (
                key_hash CHAR(64) NOT NULL PRIMARY KEY,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                window_started_at DATETIME NOT NULL,
                blocked_until DATETIME NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_auth_rate_blocked (blocked_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS auth_rate_limits');
        $pdo->exec('DROP TABLE IF EXISTS password_reset_tokens');
        $pdo->exec('DROP TABLE IF EXISTS email_verification_tokens');
    }
};
