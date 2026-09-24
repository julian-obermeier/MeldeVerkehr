<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_009_create_case_core_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_sequences (
                sequence_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
                next_number INT UNSIGNED NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cases (
                id CHAR(36) NOT NULL PRIMARY KEY,
                public_number VARCHAR(32) NOT NULL UNIQUE,
                user_id CHAR(36) NOT NULL,
                status VARCHAR(40) NOT NULL,
                observed_from DATETIME NULL,
                observed_until DATETIME NULL,
                obstruction TINYINT(1) NOT NULL DEFAULT 0,
                endangerment TINYINT(1) NOT NULL DEFAULT 0,
                damage TINYINT(1) NOT NULL DEFAULT 0,
                submitted_at DATETIME NULL,
                closed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_cases_user_status (user_id, status),
                INDEX idx_cases_public_number (public_number),
                INDEX idx_cases_created (created_at),
                CONSTRAINT fk_cases_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_status_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                old_status VARCHAR(40) NULL,
                new_status VARCHAR(40) NOT NULL,
                source VARCHAR(40) NOT NULL,
                actor_id CHAR(36) NULL,
                reason VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_case_status_case_created (case_id, created_at),
                CONSTRAINT fk_case_status_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_timeline (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                event_type VARCHAR(40) NOT NULL,
                event_key VARCHAR(120) NOT NULL,
                actor_id CHAR(36) NULL,
                payload_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_case_timeline_case_created (case_id, created_at),
                CONSTRAINT fk_case_timeline_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS vehicles (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL UNIQUE,
                license_plate_encrypted TEXT NULL,
                license_plate_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                vehicle_type VARCHAR(32) NULL,
                color VARCHAR(80) NULL,
                make VARCHAR(120) NULL,
                model VARCHAR(120) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_vehicles_plate_hash (license_plate_hash),
                CONSTRAINT fk_vehicles_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS locations (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL UNIQUE,
                latitude DECIMAL(10,7) NULL,
                longitude DECIMAL(10,7) NULL,
                street VARCHAR(190) NULL,
                house_number VARCHAR(30) NULL,
                postal_code VARCHAR(20) NULL,
                city VARCHAR(120) NULL,
                district VARCHAR(120) NULL,
                state VARCHAR(120) NULL,
                country VARCHAR(2) NOT NULL DEFAULT "DE",
                direction VARCHAR(120) NULL,
                road_side VARCHAR(80) NULL,
                location_description VARCHAR(500) NULL,
                traffic_space_type VARCHAR(40) NOT NULL DEFAULT "UNKNOWN",
                access_type VARCHAR(20) NOT NULL DEFAULT "UNCLEAR",
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_locations_city (city),
                INDEX idx_locations_geo (latitude, longitude),
                CONSTRAINT fk_locations_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS offenses (
                id CHAR(36) NOT NULL PRIMARY KEY,
                stable_key VARCHAR(120) NOT NULL UNIQUE,
                category VARCHAR(120) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS offense_versions (
                id CHAR(36) NOT NULL PRIMARY KEY,
                offense_id CHAR(36) NOT NULL,
                version INT UNSIGNED NOT NULL,
                code VARCHAR(120) NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                legal_reference VARCHAR(500) NULL,
                fine_amount DECIMAL(10,2) NULL,
                points INT NULL,
                duration_requirement VARCHAR(255) NULL,
                requires_sign TINYINT(1) NOT NULL DEFAULT 0,
                requires_duration TINYINT(1) NOT NULL DEFAULT 0,
                supports_obstruction TINYINT(1) NOT NULL DEFAULT 0,
                supports_endangerment TINYINT(1) NOT NULL DEFAULT 0,
                supports_damage TINYINT(1) NOT NULL DEFAULT 0,
                valid_from DATE NULL,
                valid_until DATE NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_offense_version (offense_id, version),
                CONSTRAINT fk_offense_versions_offense FOREIGN KEY (offense_id) REFERENCES offenses(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_offenses (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                offense_version_id CHAR(36) NOT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                user_confirmed TINYINT(1) NOT NULL DEFAULT 0,
                ai_suggested TINYINT(1) NOT NULL DEFAULT 0,
                confidence DECIMAL(5,4) NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_case_offense (case_id, offense_version_id),
                INDEX idx_case_offenses_case (case_id),
                CONSTRAINT fk_case_offenses_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_case_offenses_version FOREIGN KEY (offense_version_id) REFERENCES offense_versions(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS case_offenses');
        $pdo->exec('DROP TABLE IF EXISTS offense_versions');
        $pdo->exec('DROP TABLE IF EXISTS offenses');
        $pdo->exec('DROP TABLE IF EXISTS locations');
        $pdo->exec('DROP TABLE IF EXISTS vehicles');
        $pdo->exec('DROP TABLE IF EXISTS case_timeline');
        $pdo->exec('DROP TABLE IF EXISTS case_status_history');
        $pdo->exec('DROP TABLE IF EXISTS cases');
        $pdo->exec('DROP TABLE IF EXISTS case_sequences');
    }
};
