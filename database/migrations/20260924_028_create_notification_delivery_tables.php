<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_028_create_notification_delivery_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS notification_channel_deliveries (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                event_key VARCHAR(100) NOT NULL,
                unique_key VARCHAR(190) NOT NULL,
                channel VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "PENDING",
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                last_error VARCHAR(2000) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                sent_at DATETIME NULL,
                UNIQUE KEY uq_notification_channel_delivery (user_id, unique_key, channel),
                INDEX idx_notification_delivery_retry (status, channel, updated_at),
                CONSTRAINT fk_notification_delivery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS web_push_subscriptions (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                endpoint_encrypted LONGTEXT NOT NULL,
                endpoint_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                p256dh VARCHAR(255) NULL,
                auth_secret VARCHAR(255) NULL,
                status VARCHAR(20) NOT NULL DEFAULT "ACTIVE",
                user_agent VARCHAR(500) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                last_success_at DATETIME NULL,
                UNIQUE KEY uq_web_push_user_endpoint (user_id, endpoint_hash),
                INDEX idx_web_push_active (user_id, status, updated_at),
                CONSTRAINT fk_web_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS web_push_subscriptions');
        $pdo->exec('DROP TABLE IF EXISTS notification_channel_deliveries');
    }
};
