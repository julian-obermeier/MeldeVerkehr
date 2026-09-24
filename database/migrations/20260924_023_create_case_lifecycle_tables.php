<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_023_create_case_lifecycle_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_versions (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                version_no INT UNSIGNED NOT NULL,
                version_type VARCHAR(40) NOT NULL,
                reason VARCHAR(500) NULL,
                snapshot_json LONGTEXT NOT NULL,
                snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                created_by CHAR(36) NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_case_versions_number (case_id, version_no),
                INDEX idx_case_versions_case_created (case_id, created_at),
                CONSTRAINT fk_case_versions_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_amendments (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                case_version_id CHAR(36) NULL,
                amendment_no INT UNSIGNED NOT NULL,
                title VARCHAR(190) NOT NULL,
                content TEXT NOT NULL,
                created_by CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_case_amendments_number (case_id, amendment_no),
                INDEX idx_case_amendments_case_created (case_id, created_at),
                CONSTRAINT fk_case_amendments_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_case_amendments_version FOREIGN KEY (case_version_id) REFERENCES case_versions(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_correction_requests (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                before_version_id CHAR(36) NOT NULL,
                completed_version_id CHAR(36) NULL,
                previous_status VARCHAR(40) NOT NULL,
                category VARCHAR(60) NOT NULL,
                original_value TEXT NULL,
                corrected_value TEXT NOT NULL,
                reason TEXT NOT NULL,
                status VARCHAR(24) NOT NULL,
                requested_by CHAR(36) NOT NULL,
                requested_at DATETIME NOT NULL,
                completed_by CHAR(36) NULL,
                completed_at DATETIME NULL,
                completion_note TEXT NULL,
                INDEX idx_case_corrections_case_status (case_id, status),
                CONSTRAINT fk_case_corrections_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_case_corrections_before_version FOREIGN KEY (before_version_id) REFERENCES case_versions(id) ON DELETE RESTRICT,
                CONSTRAINT fk_case_corrections_completed_version FOREIGN KEY (completed_version_id) REFERENCES case_versions(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_withdrawals (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                before_version_id CHAR(36) NOT NULL,
                closure_version_id CHAR(36) NULL,
                previous_status VARCHAR(40) NOT NULL,
                reason TEXT NOT NULL,
                status VARCHAR(24) NOT NULL,
                requested_by CHAR(36) NOT NULL,
                requested_at DATETIME NOT NULL,
                completed_by CHAR(36) NULL,
                completed_at DATETIME NULL,
                completion_note TEXT NULL,
                INDEX idx_case_withdrawals_case_status (case_id, status),
                CONSTRAINT fk_case_withdrawals_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_case_withdrawals_before_version FOREIGN KEY (before_version_id) REFERENCES case_versions(id) ON DELETE RESTRICT,
                CONSTRAINT fk_case_withdrawals_closure_version FOREIGN KEY (closure_version_id) REFERENCES case_versions(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_closure_records (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                closure_no INT UNSIGNED NOT NULL,
                closure_reason VARCHAR(60) NOT NULL,
                closure_note TEXT NULL,
                snapshot_version_id CHAR(36) NOT NULL,
                dossier_json LONGTEXT NOT NULL,
                dossier_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                closed_by CHAR(36) NOT NULL,
                closed_at DATETIME NOT NULL,
                archived_at DATETIME NULL,
                UNIQUE KEY uq_case_closure_number (case_id, closure_no),
                INDEX idx_case_closure_case_created (case_id, closed_at),
                CONSTRAINT fk_case_closure_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_case_closure_version FOREIGN KEY (snapshot_version_id) REFERENCES case_versions(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS case_closure_records');
        $pdo->exec('DROP TABLE IF EXISTS case_withdrawals');
        $pdo->exec('DROP TABLE IF EXISTS case_correction_requests');
        $pdo->exec('DROP TABLE IF EXISTS case_amendments');
        $pdo->exec('DROP TABLE IF EXISTS case_versions');
    }
};
