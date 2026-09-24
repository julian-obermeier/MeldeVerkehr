<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_016_create_assist_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS evidence_quality_metrics (
                id CHAR(36) NOT NULL PRIMARY KEY,
                evidence_id CHAR(36) NOT NULL,
                version_no INT UNSIGNED NOT NULL,
                source_variant VARCHAR(20) NOT NULL DEFAULT "WORKING",
                width INT UNSIGNED NOT NULL,
                height INT UNSIGNED NOT NULL,
                brightness_mean DECIMAL(8,4) NULL,
                contrast_stddev DECIMAL(8,4) NULL,
                sharpness_score DECIMAL(14,4) NULL,
                resolution_state VARCHAR(30) NOT NULL,
                brightness_state VARCHAR(30) NOT NULL,
                contrast_state VARCHAR(30) NOT NULL,
                sharpness_state VARCHAR(30) NOT NULL,
                overall_state VARCHAR(30) NOT NULL,
                metrics_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_evidence_quality_version (evidence_id, version_no),
                INDEX idx_evidence_quality_state (evidence_id, overall_state),
                CONSTRAINT fk_evidence_quality_item FOREIGN KEY (evidence_id) REFERENCES evidence_items(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS assist_runs (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                evidence_id CHAR(36) NULL,
                provider VARCHAR(100) NOT NULL,
                purpose VARCHAR(50) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT "RUNNING",
                input_variant VARCHAR(20) NULL,
                input_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                output_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                metadata_json LONGTEXT NULL,
                error_message VARCHAR(2000) NULL,
                requested_by_user_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                INDEX idx_assist_run_case (case_id, created_at),
                INDEX idx_assist_run_evidence (evidence_id, created_at),
                INDEX idx_assist_run_status (status, purpose),
                CONSTRAINT fk_assist_run_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_assist_run_evidence FOREIGN KEY (evidence_id) REFERENCES evidence_items(id) ON DELETE SET NULL,
                CONSTRAINT fk_assist_run_user FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS assist_suggestions (
                id CHAR(36) NOT NULL PRIMARY KEY,
                run_id CHAR(36) NOT NULL,
                case_id CHAR(36) NOT NULL,
                evidence_id CHAR(36) NULL,
                suggestion_type VARCHAR(50) NOT NULL,
                value_json LONGTEXT NOT NULL,
                confidence DECIMAL(5,4) NULL,
                status VARCHAR(30) NOT NULL DEFAULT "PENDING",
                decided_by_user_id CHAR(36) NULL,
                created_at DATETIME NOT NULL,
                decided_at DATETIME NULL,
                INDEX idx_assist_suggestion_case (case_id, suggestion_type, status, created_at),
                INDEX idx_assist_suggestion_evidence (evidence_id, suggestion_type, status),
                CONSTRAINT fk_assist_suggestion_run FOREIGN KEY (run_id) REFERENCES assist_runs(id) ON DELETE CASCADE,
                CONSTRAINT fk_assist_suggestion_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_assist_suggestion_evidence FOREIGN KEY (evidence_id) REFERENCES evidence_items(id) ON DELETE SET NULL,
                CONSTRAINT fk_assist_suggestion_user FOREIGN KEY (decided_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS assist_suggestions');
        $pdo->exec('DROP TABLE IF EXISTS assist_runs');
        $pdo->exec('DROP TABLE IF EXISTS evidence_quality_metrics');
    }
};
