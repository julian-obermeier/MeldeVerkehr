<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_011_create_evidence_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS evidence_items (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                category VARCHAR(40) NOT NULL,
                source VARCHAR(30) NOT NULL DEFAULT "UPLOAD",
                original_filename VARCHAR(255) NOT NULL,
                captured_at DATETIME NULL,
                status VARCHAR(30) NOT NULL DEFAULT "ACTIVE",
                quality_state VARCHAR(30) NOT NULL DEFAULT "PENDING",
                created_by_user_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_evidence_case_status (case_id, status),
                INDEX idx_evidence_case_category (case_id, category),
                CONSTRAINT fk_evidence_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_evidence_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS evidence_versions (
                id CHAR(36) NOT NULL PRIMARY KEY,
                evidence_id CHAR(36) NOT NULL,
                variant VARCHAR(20) NOT NULL,
                version_no INT UNSIGNED NOT NULL,
                storage_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL,
                sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                width INT UNSIGNED NULL,
                height INT UNSIGNED NULL,
                processing_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_evidence_variant_version (evidence_id, variant, version_no),
                INDEX idx_evidence_version_hash (sha256),
                CONSTRAINT fk_evidence_version_item FOREIGN KEY (evidence_id) REFERENCES evidence_items(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS evidence_metadata (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                evidence_id CHAR(36) NOT NULL,
                metadata_key VARCHAR(120) NOT NULL,
                value_json LONGTEXT NOT NULL,
                source VARCHAR(30) NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_evidence_metadata_item (evidence_id, metadata_key),
                CONSTRAINT fk_evidence_metadata_item FOREIGN KEY (evidence_id) REFERENCES evidence_items(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS evidence_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                evidence_id CHAR(36) NOT NULL,
                event_type VARCHAR(80) NOT NULL,
                actor_user_id CHAR(36) NULL,
                payload_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_evidence_events_item (evidence_id, id),
                CONSTRAINT fk_evidence_event_item FOREIGN KEY (evidence_id) REFERENCES evidence_items(id) ON DELETE CASCADE,
                CONSTRAINT fk_evidence_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS evidence_events');
        $pdo->exec('DROP TABLE IF EXISTS evidence_metadata');
        $pdo->exec('DROP TABLE IF EXISTS evidence_versions');
        $pdo->exec('DROP TABLE IF EXISTS evidence_items');
    }
};
