<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_026_create_authority_inquiry_response_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS authority_inquiry_responses (
                id CHAR(36) NOT NULL PRIMARY KEY,
                inquiry_id CHAR(36) NOT NULL,
                case_id CHAR(36) NOT NULL,
                version_no INT UNSIGNED NOT NULL,
                body_encrypted LONGTEXT NOT NULL,
                body_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                submitted_by_user_id CHAR(36) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT "SUBMITTED",
                review_note_encrypted LONGTEXT NULL,
                reviewed_by_user_id CHAR(36) NULL,
                submitted_at DATETIME NOT NULL,
                reviewed_at DATETIME NULL,
                UNIQUE KEY uq_authority_inquiry_response_version (inquiry_id, version_no),
                INDEX idx_authority_inquiry_response_case (case_id, submitted_at),
                INDEX idx_authority_inquiry_response_status (status, submitted_at),
                CONSTRAINT fk_authority_inquiry_response_inquiry FOREIGN KEY (inquiry_id) REFERENCES authority_portal_inquiries(id) ON DELETE CASCADE,
                CONSTRAINT fk_authority_inquiry_response_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_authority_inquiry_response_submitter FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT fk_authority_inquiry_response_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS authority_inquiry_responses');
    }
};
