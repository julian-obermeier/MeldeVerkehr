<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_029_create_offline_evidence_receipts';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS offline_evidence_uploads (
                client_upload_id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                case_id CHAR(36) NOT NULL,
                reserved_evidence_id CHAR(36) NOT NULL,
                client_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "PROCESSING",
                original_filename VARCHAR(255) NULL,
                category VARCHAR(40) NULL,
                last_error VARCHAR(1000) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                UNIQUE KEY uq_offline_reserved_evidence (reserved_evidence_id),
                INDEX idx_offline_evidence_owner (user_id, case_id, status, updated_at),
                CONSTRAINT fk_offline_evidence_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_offline_evidence_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS offline_evidence_uploads');
    }
};
