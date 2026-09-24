<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_017_create_map_analytics_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS problem_areas (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                name VARCHAR(190) NOT NULL,
                center_latitude DECIMAL(10,7) NOT NULL,
                center_longitude DECIMAL(10,7) NOT NULL,
                radius_m INT UNSIGNED NOT NULL DEFAULT 150,
                status VARCHAR(30) NOT NULL DEFAULT "ACTIVE",
                source VARCHAR(20) NOT NULL DEFAULT "MANUAL",
                city VARCHAR(120) NULL,
                street VARCHAR(190) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_problem_area_user_status (user_id, status),
                INDEX idx_problem_area_geo (center_latitude, center_longitude),
                CONSTRAINT fk_problem_area_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS problem_area_cases (
                problem_area_id CHAR(36) NOT NULL,
                case_id CHAR(36) NOT NULL,
                distance_m DECIMAL(10,2) NULL,
                assignment_source VARCHAR(20) NOT NULL DEFAULT "AUTO",
                created_at DATETIME NOT NULL,
                PRIMARY KEY (problem_area_id, case_id),
                INDEX idx_problem_area_case_case (case_id),
                CONSTRAINT fk_problem_area_case_area FOREIGN KEY (problem_area_id) REFERENCES problem_areas(id) ON DELETE CASCADE,
                CONSTRAINT fk_problem_area_case_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS problem_area_snapshots (
                id CHAR(36) NOT NULL PRIMARY KEY,
                problem_area_id CHAR(36) NOT NULL,
                version_no INT UNSIGNED NOT NULL,
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                metrics_json LONGTEXT NOT NULL,
                metrics_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                created_by_user_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_problem_area_snapshot_version (problem_area_id, version_no),
                INDEX idx_problem_area_snapshot_period (problem_area_id, period_start, period_end),
                CONSTRAINT fk_problem_area_snapshot_area FOREIGN KEY (problem_area_id) REFERENCES problem_areas(id) ON DELETE CASCADE,
                CONSTRAINT fk_problem_area_snapshot_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS municipal_reports (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                problem_area_id CHAR(36) NOT NULL,
                snapshot_id CHAR(36) NOT NULL,
                version_no INT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                report_json LONGTEXT NOT NULL,
                report_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT "DRAFT",
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_municipal_report_version (problem_area_id, version_no),
                INDEX idx_municipal_report_user (user_id, status, created_at),
                CONSTRAINT fk_municipal_report_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_municipal_report_area FOREIGN KEY (problem_area_id) REFERENCES problem_areas(id) ON DELETE CASCADE,
                CONSTRAINT fk_municipal_report_snapshot FOREIGN KEY (snapshot_id) REFERENCES problem_area_snapshots(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS municipal_reports');
        $pdo->exec('DROP TABLE IF EXISTS problem_area_snapshots');
        $pdo->exec('DROP TABLE IF EXISTS problem_area_cases');
        $pdo->exec('DROP TABLE IF EXISTS problem_areas');
    }
};
