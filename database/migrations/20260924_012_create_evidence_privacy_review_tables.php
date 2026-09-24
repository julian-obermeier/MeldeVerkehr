<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_012_create_evidence_privacy_review_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS evidence_privacy_regions (
                id CHAR(36) NOT NULL PRIMARY KEY,
                evidence_id CHAR(36) NOT NULL,
                region_type VARCHAR(40) NOT NULL,
                x DECIMAL(8,7) NOT NULL,
                y DECIMAL(8,7) NOT NULL,
                width DECIMAL(8,7) NOT NULL,
                height DECIMAL(8,7) NOT NULL,
                source VARCHAR(30) NOT NULL DEFAULT "MANUAL",
                status VARCHAR(30) NOT NULL DEFAULT "CONFIRMED",
                created_by_user_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_privacy_evidence_status (evidence_id, status),
                CONSTRAINT fk_privacy_evidence FOREIGN KEY (evidence_id) REFERENCES evidence_items(id) ON DELETE CASCADE,
                CONSTRAINT fk_privacy_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_evidence_reviews (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                review_version INT UNSIGNED NOT NULL,
                missing_json LONGTEXT NOT NULL,
                warnings_json LONGTEXT NOT NULL,
                acknowledged_warnings TINYINT(1) NOT NULL DEFAULT 0,
                created_by_user_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                confirmed_at DATETIME NULL,
                UNIQUE KEY uq_case_evidence_review (case_id, review_version),
                INDEX idx_case_evidence_review_case (case_id, created_at),
                CONSTRAINT fk_evidence_review_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_evidence_review_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS evidence_packages (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                version_no INT UNSIGNED NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT "FROZEN",
                manifest_json LONGTEXT NOT NULL,
                manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                created_by_user_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                frozen_at DATETIME NOT NULL,
                UNIQUE KEY uq_evidence_package_version (case_id, version_no),
                INDEX idx_evidence_package_case (case_id, created_at),
                CONSTRAINT fk_evidence_package_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_evidence_package_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS evidence_package_items (
                package_id CHAR(36) NOT NULL,
                evidence_id CHAR(36) NOT NULL,
                order_no INT UNSIGNED NOT NULL,
                variant VARCHAR(20) NOT NULL,
                version_no INT UNSIGNED NOT NULL,
                sha256_snapshot CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                category_snapshot VARCHAR(40) NOT NULL,
                PRIMARY KEY (package_id, evidence_id),
                UNIQUE KEY uq_package_order (package_id, order_no),
                CONSTRAINT fk_package_item_package FOREIGN KEY (package_id) REFERENCES evidence_packages(id) ON DELETE CASCADE,
                CONSTRAINT fk_package_item_evidence FOREIGN KEY (evidence_id) REFERENCES evidence_items(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS evidence_package_items');
        $pdo->exec('DROP TABLE IF EXISTS evidence_packages');
        $pdo->exec('DROP TABLE IF EXISTS case_evidence_reviews');
        $pdo->exec('DROP TABLE IF EXISTS evidence_privacy_regions');
    }
};
