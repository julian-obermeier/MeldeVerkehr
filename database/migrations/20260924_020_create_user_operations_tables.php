<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_020_create_user_operations_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS saved_case_filters (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                name VARCHAR(120) NOT NULL,
                filter_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_saved_filter_name (user_id, name),
                INDEX idx_saved_filter_user (user_id, updated_at),
                CONSTRAINT fk_saved_filter_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_notifications (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                case_id CHAR(36) NULL,
                event_key VARCHAR(100) NOT NULL,
                unique_key VARCHAR(190) NULL,
                priority VARCHAR(20) NOT NULL DEFAULT "NORMAL",
                title VARCHAR(255) NOT NULL,
                body_encrypted TEXT NULL,
                action_url VARCHAR(700) NULL,
                created_at DATETIME NOT NULL,
                read_at DATETIME NULL,
                UNIQUE KEY uq_user_notification_unique (user_id, unique_key),
                INDEX idx_user_notification_unread (user_id, read_at, created_at),
                INDEX idx_user_notification_case (case_id, created_at),
                CONSTRAINT fk_user_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_notification_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_notification_preferences (
                user_id CHAR(36) NOT NULL,
                event_key VARCHAR(100) NOT NULL,
                in_app_enabled TINYINT(1) NOT NULL DEFAULT 1,
                email_enabled TINYINT(1) NOT NULL DEFAULT 1,
                push_enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (user_id, event_key),
                CONSTRAINT fk_notification_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS export_artifacts (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                export_type VARCHAR(50) NOT NULL,
                format VARCHAR(10) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                storage_path VARCHAR(700) NOT NULL,
                mime_type VARCHAR(190) NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL,
                sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                includes_sensitive TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT "READY",
                created_at DATETIME NOT NULL,
                expires_at DATETIME NULL,
                INDEX idx_export_user (user_id, created_at),
                INDEX idx_export_expiry (status, expires_at),
                CONSTRAINT fk_export_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS retention_schedules (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                case_id CHAR(36) NULL,
                data_type VARCHAR(50) NOT NULL,
                resource_type VARCHAR(50) NOT NULL,
                resource_id CHAR(36) NULL,
                eligible_at DATETIME NULL,
                status VARCHAR(20) NOT NULL DEFAULT "PLANNED",
                policy_key VARCHAR(100) NOT NULL,
                reason VARCHAR(500) NULL,
                created_at DATETIME NOT NULL,
                processed_at DATETIME NULL,
                UNIQUE KEY uq_retention_resource (user_id, data_type, resource_type, resource_id, policy_key),
                INDEX idx_retention_due (status, eligible_at),
                INDEX idx_retention_user (user_id, status, eligible_at),
                CONSTRAINT fk_retention_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_retention_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS account_deletion_requests (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "REQUESTED",
                requested_at DATETIME NOT NULL,
                reviewed_at DATETIME NULL,
                completed_at DATETIME NULL,
                summary_json LONGTEXT NOT NULL,
                UNIQUE KEY uq_account_deletion_active (user_id, status),
                INDEX idx_account_deletion_status (status, requested_at),
                CONSTRAINT fk_account_deletion_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS account_deletion_requests');
        $pdo->exec('DROP TABLE IF EXISTS retention_schedules');
        $pdo->exec('DROP TABLE IF EXISTS export_artifacts');
        $pdo->exec('DROP TABLE IF EXISTS user_notification_preferences');
        $pdo->exec('DROP TABLE IF EXISTS user_notifications');
        $pdo->exec('DROP TABLE IF EXISTS saved_case_filters');
    }
};
