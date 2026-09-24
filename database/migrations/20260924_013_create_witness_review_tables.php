<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_013_create_witness_review_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_observation_statements (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                version_no INT UNSIGNED NOT NULL,
                observation_text TEXT NOT NULL,
                impact_text TEXT NULL,
                context_text TEXT NULL,
                created_by_user_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_observation_statement_version (case_id, version_no),
                INDEX idx_observation_statement_case (case_id, created_at),
                CONSTRAINT fk_observation_statement_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_observation_statement_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_narratives (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                version_no INT UNSIGNED NOT NULL,
                observation_statement_id CHAR(36) NOT NULL,
                generated_text TEXT NOT NULL,
                final_text TEXT NOT NULL,
                generator_version VARCHAR(60) NOT NULL,
                created_by_user_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_case_narrative_version (case_id, version_no),
                INDEX idx_case_narrative_case (case_id, created_at),
                CONSTRAINT fk_case_narrative_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_case_narrative_observation FOREIGN KEY (observation_statement_id) REFERENCES case_observation_statements(id) ON DELETE RESTRICT,
                CONSTRAINT fk_case_narrative_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS witness_reports (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                version_no INT UNSIGNED NOT NULL,
                observation_statement_id CHAR(36) NOT NULL,
                narrative_id CHAR(36) NOT NULL,
                evidence_package_id CHAR(36) NOT NULL,
                snapshot_json LONGTEXT NOT NULL,
                snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                confirmed_by_user_id CHAR(36) NULL,
                confirmed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_witness_report_version (case_id, version_no),
                INDEX idx_witness_report_case (case_id, created_at),
                CONSTRAINT fk_witness_report_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_witness_report_observation FOREIGN KEY (observation_statement_id) REFERENCES case_observation_statements(id) ON DELETE RESTRICT,
                CONSTRAINT fk_witness_report_narrative FOREIGN KEY (narrative_id) REFERENCES case_narratives(id) ON DELETE RESTRICT,
                CONSTRAINT fk_witness_report_package FOREIGN KEY (evidence_package_id) REFERENCES evidence_packages(id) ON DELETE RESTRICT,
                CONSTRAINT fk_witness_report_user FOREIGN KEY (confirmed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_declarations (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                witness_report_id CHAR(36) NOT NULL UNIQUE,
                declaration_key VARCHAR(80) NOT NULL,
                declaration_version INT UNSIGNED NOT NULL,
                declaration_text TEXT NOT NULL,
                metadata_json LONGTEXT NULL,
                accepted_by_user_id CHAR(36) NOT NULL,
                accepted_at DATETIME NOT NULL,
                INDEX idx_case_declaration_case (case_id, accepted_at),
                CONSTRAINT fk_case_declaration_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_case_declaration_report FOREIGN KEY (witness_report_id) REFERENCES witness_reports(id) ON DELETE RESTRICT,
                CONSTRAINT fk_case_declaration_user FOREIGN KEY (accepted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_quality_reviews (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                version_no INT UNSIGNED NOT NULL,
                red_json LONGTEXT NOT NULL,
                yellow_json LONGTEXT NOT NULL,
                green_json LONGTEXT NOT NULL,
                acknowledged_yellow TINYINT(1) NOT NULL DEFAULT 0,
                created_by_user_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                confirmed_at DATETIME NULL,
                UNIQUE KEY uq_case_quality_review_version (case_id, version_no),
                INDEX idx_case_quality_review_case (case_id, created_at),
                CONSTRAINT fk_case_quality_review_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_case_quality_review_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS case_quality_reviews');
        $pdo->exec('DROP TABLE IF EXISTS case_declarations');
        $pdo->exec('DROP TABLE IF EXISTS witness_reports');
        $pdo->exec('DROP TABLE IF EXISTS case_narratives');
        $pdo->exec('DROP TABLE IF EXISTS case_observation_statements');
    }
};
