<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_014_create_authority_dispatch_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS authorities (
                id CHAR(36) NOT NULL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                authority_type VARCHAR(80) NOT NULL DEFAULT "TRAFFIC_ENFORCEMENT",
                country_code CHAR(2) NOT NULL DEFAULT "DE",
                state_code VARCHAR(20) NULL,
                district VARCHAR(190) NULL,
                municipality VARCHAR(190) NULL,
                status VARCHAR(30) NOT NULL DEFAULT "UNVERIFIED",
                source_note VARCHAR(500) NULL,
                last_verified_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_authority_geo (country_code, state_code, district, municipality),
                INDEX idx_authority_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS authority_endpoints (
                id CHAR(36) NOT NULL PRIMARY KEY,
                authority_id CHAR(36) NOT NULL,
                channel VARCHAR(30) NOT NULL,
                endpoint_value VARCHAR(1000) NOT NULL,
                priority INT NOT NULL DEFAULT 100,
                status VARCHAR(30) NOT NULL DEFAULT "UNVERIFIED",
                max_total_bytes BIGINT UNSIGNED NULL,
                last_verified_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_authority_endpoint_route (authority_id, status, priority),
                CONSTRAINT fk_authority_endpoint_authority FOREIGN KEY (authority_id) REFERENCES authorities(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS authority_requirement_versions (
                id CHAR(36) NOT NULL PRIMARY KEY,
                authority_id CHAR(36) NOT NULL,
                version_no INT UNSIGNED NOT NULL,
                required_fields_json LONGTEXT NOT NULL,
                accepted_mime_json LONGTEXT NOT NULL,
                max_attachment_bytes BIGINT UNSIGNED NULL,
                max_total_bytes BIGINT UNSIGNED NULL,
                notes TEXT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_authority_requirement_version (authority_id, version_no),
                INDEX idx_authority_requirement_active (authority_id, active, version_no),
                CONSTRAINT fk_authority_requirement_authority FOREIGN KEY (authority_id) REFERENCES authorities(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS authority_routing_rules (
                id CHAR(36) NOT NULL PRIMARY KEY,
                authority_id CHAR(36) NOT NULL,
                endpoint_id CHAR(36) NULL,
                country_code CHAR(2) NULL,
                state_code VARCHAR(20) NULL,
                postal_code VARCHAR(20) NULL,
                postal_prefix VARCHAR(20) NULL,
                city VARCHAR(190) NULL,
                district VARCHAR(190) NULL,
                offense_category VARCHAR(100) NULL,
                priority INT NOT NULL DEFAULT 100,
                certainty VARCHAR(20) NOT NULL DEFAULT "EXACT",
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_authority_route_active (active, priority),
                INDEX idx_authority_route_postal (postal_code, postal_prefix),
                INDEX idx_authority_route_city (city),
                CONSTRAINT fk_authority_route_authority FOREIGN KEY (authority_id) REFERENCES authorities(id) ON DELETE CASCADE,
                CONSTRAINT fk_authority_route_endpoint FOREIGN KEY (endpoint_id) REFERENCES authority_endpoints(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS dispatch_packages (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                version_no INT UNSIGNED NOT NULL,
                authority_id CHAR(36) NOT NULL,
                endpoint_id CHAR(36) NOT NULL,
                witness_report_id CHAR(36) NOT NULL,
                evidence_package_id CHAR(36) NOT NULL,
                quality_review_id CHAR(36) NOT NULL,
                manifest_encrypted LONGTEXT NOT NULL,
                manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT "FROZEN",
                created_by_user_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                frozen_at DATETIME NOT NULL,
                UNIQUE KEY uq_dispatch_package_version (case_id, version_no),
                INDEX idx_dispatch_package_case (case_id, created_at),
                CONSTRAINT fk_dispatch_package_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_dispatch_package_authority FOREIGN KEY (authority_id) REFERENCES authorities(id) ON DELETE RESTRICT,
                CONSTRAINT fk_dispatch_package_endpoint FOREIGN KEY (endpoint_id) REFERENCES authority_endpoints(id) ON DELETE RESTRICT,
                CONSTRAINT fk_dispatch_package_witness FOREIGN KEY (witness_report_id) REFERENCES witness_reports(id) ON DELETE RESTRICT,
                CONSTRAINT fk_dispatch_package_evidence FOREIGN KEY (evidence_package_id) REFERENCES evidence_packages(id) ON DELETE RESTRICT,
                CONSTRAINT fk_dispatch_package_quality FOREIGN KEY (quality_review_id) REFERENCES case_quality_reviews(id) ON DELETE RESTRICT,
                CONSTRAINT fk_dispatch_package_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS dispatches (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                dispatch_package_id CHAR(36) NOT NULL,
                authority_id CHAR(36) NOT NULL,
                endpoint_id CHAR(36) NOT NULL,
                channel VARCHAR(30) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT "CREATED",
                queue_job_uuid CHAR(36) NULL,
                requested_by_user_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                queued_at DATETIME NULL,
                sent_at DATETIME NULL,
                completed_at DATETIME NULL,
                last_error VARCHAR(2000) NULL,
                INDEX idx_dispatch_case_status (case_id, status),
                INDEX idx_dispatch_queue_job (queue_job_uuid),
                CONSTRAINT fk_dispatch_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_dispatch_package FOREIGN KEY (dispatch_package_id) REFERENCES dispatch_packages(id) ON DELETE RESTRICT,
                CONSTRAINT fk_dispatch_authority FOREIGN KEY (authority_id) REFERENCES authorities(id) ON DELETE RESTRICT,
                CONSTRAINT fk_dispatch_endpoint FOREIGN KEY (endpoint_id) REFERENCES authority_endpoints(id) ON DELETE RESTRICT,
                CONSTRAINT fk_dispatch_user FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS dispatch_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                dispatch_id CHAR(36) NOT NULL,
                attempt_no INT UNSIGNED NOT NULL,
                status VARCHAR(30) NOT NULL,
                provider_reference VARCHAR(255) NULL,
                response_json LONGTEXT NULL,
                started_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                UNIQUE KEY uq_dispatch_attempt (dispatch_id, attempt_no),
                INDEX idx_dispatch_attempt_status (dispatch_id, status),
                CONSTRAINT fk_dispatch_attempt_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatches(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS dispatch_dry_run_outbox (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                dispatch_id CHAR(36) NOT NULL,
                recipient VARCHAR(1000) NOT NULL,
                subject VARCHAR(500) NOT NULL,
                body_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                attachment_manifest_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_dry_run_dispatch (dispatch_id),
                CONSTRAINT fk_dry_run_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatches(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS dispatch_dry_run_outbox');
        $pdo->exec('DROP TABLE IF EXISTS dispatch_attempts');
        $pdo->exec('DROP TABLE IF EXISTS dispatches');
        $pdo->exec('DROP TABLE IF EXISTS dispatch_packages');
        $pdo->exec('DROP TABLE IF EXISTS authority_routing_rules');
        $pdo->exec('DROP TABLE IF EXISTS authority_requirement_versions');
        $pdo->exec('DROP TABLE IF EXISTS authority_endpoints');
        $pdo->exec('DROP TABLE IF EXISTS authorities');
    }
};
