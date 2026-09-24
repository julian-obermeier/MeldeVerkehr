<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_022_create_release_operations_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS system_backups (
                id CHAR(36) NOT NULL PRIMARY KEY,
                backup_type VARCHAR(30) NOT NULL DEFAULT "FULL",
                status VARCHAR(20) NOT NULL DEFAULT "CREATING",
                storage_path VARCHAR(700) NOT NULL,
                manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                database_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                runtime_files INT UNSIGNED NOT NULL DEFAULT 0,
                runtime_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                encrypted TINYINT(1) NOT NULL DEFAULT 1,
                created_by_user_id CHAR(36) NULL,
                created_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                last_error VARCHAR(2000) NULL,
                INDEX idx_system_backup_status (status, created_at),
                CONSTRAINT fk_system_backup_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS system_update_runs (
                id CHAR(36) NOT NULL PRIMARY KEY,
                from_version VARCHAR(50) NOT NULL,
                to_version VARCHAR(50) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT "PREFLIGHT",
                backup_id CHAR(36) NULL,
                migration_count_before INT UNSIGNED NOT NULL DEFAULT 0,
                migration_count_after INT UNSIGNED NOT NULL DEFAULT 0,
                notes TEXT NULL,
                created_by_user_id CHAR(36) NULL,
                started_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                INDEX idx_update_status (status, started_at),
                CONSTRAINT fk_update_backup FOREIGN KEY (backup_id) REFERENCES system_backups(id) ON DELETE SET NULL,
                CONSTRAINT fk_update_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS request_rate_limits (
                key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
                bucket VARCHAR(80) NOT NULL,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                window_started_at DATETIME NOT NULL,
                blocked_until DATETIME NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_request_rate_bucket (bucket, updated_at),
                INDEX idx_request_rate_blocked (blocked_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS request_rate_limits');
        $pdo->exec('DROP TABLE IF EXISTS system_update_runs');
        $pdo->exec('DROP TABLE IF EXISTS system_backups');
    }
};
