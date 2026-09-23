<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_008_create_strong_auth_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_totp (
                user_id CHAR(36) NOT NULL PRIMARY KEY,
                secret_encrypted TEXT NOT NULL,
                confirmed_at DATETIME NULL,
                recovery_codes_json LONGTEXT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT fk_user_totp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS passkey_credentials (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                credential_id VARCHAR(1024) NOT NULL UNIQUE,
                public_key_pem TEXT NOT NULL,
                algorithm INT NOT NULL,
                sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                transports_json TEXT NULL,
                label VARCHAR(120) NULL,
                created_at DATETIME NOT NULL,
                last_used_at DATETIME NULL,
                INDEX idx_passkeys_user (user_id),
                CONSTRAINT fk_passkeys_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS passkey_credentials');
        $pdo->exec('DROP TABLE IF EXISTS user_totp');
    }
};
