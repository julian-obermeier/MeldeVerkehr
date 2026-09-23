<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_005_create_audit_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS audit_state (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                current_hash CHAR(64) NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'INSERT IGNORE INTO audit_state (id, current_hash, updated_at)
             VALUES (1, NULL, UTC_TIMESTAMP())'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                actor_type VARCHAR(32) NOT NULL,
                actor_id VARCHAR(64) NULL,
                action VARCHAR(120) NOT NULL,
                entity_type VARCHAR(120) NULL,
                entity_id VARCHAR(190) NULL,
                metadata_json LONGTEXT NULL,
                previous_hash CHAR(64) NULL,
                entry_hash CHAR(64) NOT NULL UNIQUE,
                created_at DATETIME NOT NULL,
                INDEX idx_audit_actor (actor_type, actor_id),
                INDEX idx_audit_action (action),
                INDEX idx_audit_entity (entity_type, entity_id),
                INDEX idx_audit_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS audit_logs');
        $pdo->exec('DROP TABLE IF EXISTS audit_state');
    }
};
