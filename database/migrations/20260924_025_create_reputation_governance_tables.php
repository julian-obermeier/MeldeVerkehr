<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_025_create_reputation_governance_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS reputation_policies (
                category VARCHAR(30) NOT NULL PRIMARY KEY,
                daily_positive_cap INT UNSIGNED NOT NULL,
                full_rate_events SMALLINT UNSIGNED NOT NULL,
                reduced_rate_events SMALLINT UNSIGNED NOT NULL,
                reduced_multiplier DECIMAL(5,2) NOT NULL,
                tail_multiplier DECIMAL(5,2) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $stmt = $pdo->prepare(
            'INSERT INTO reputation_policies
             (category, daily_positive_cap, full_rate_events, reduced_rate_events,
              reduced_multiplier, tail_multiplier, active, updated_at)
             VALUES (:category, :cap, :full_rate, :reduced_rate, :reduced_multiplier, :tail_multiplier, 1, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                daily_positive_cap = VALUES(daily_positive_cap),
                full_rate_events = VALUES(full_rate_events),
                reduced_rate_events = VALUES(reduced_rate_events),
                reduced_multiplier = VALUES(reduced_multiplier),
                tail_multiplier = VALUES(tail_multiplier),
                active = 1,
                updated_at = VALUES(updated_at)'
        );

        foreach ([
            ['COMMUNITY', 60, 5, 15, 0.50, 0.25],
            ['REPORT_QUALITY', 40, 5, 12, 0.50, 0.25],
            ['PROBLEM_REPORT', 30, 5, 12, 0.50, 0.25],
            ['AUTHORITY_INFO', 30, 5, 10, 0.50, 0.25],
        ] as [$category, $cap, $full, $reduced, $middle, $tail]) {
            $stmt->execute([
                'category' => $category,
                'cap' => $cap,
                'full_rate' => $full,
                'reduced_rate' => $reduced,
                'reduced_multiplier' => $middle,
                'tail_multiplier' => $tail,
            ]);
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS reputation_daily_usage (
                user_id CHAR(36) NOT NULL,
                category VARCHAR(30) NOT NULL,
                usage_date DATE NOT NULL,
                event_count INT UNSIGNED NOT NULL DEFAULT 0,
                awarded_positive_points INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (user_id, category, usage_date),
                CONSTRAINT fk_reputation_daily_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS reputation_event_details (
                event_id CHAR(36) NOT NULL PRIMARY KEY,
                requested_points INT NOT NULL,
                effective_points INT NOT NULL,
                multiplier DECIMAL(5,2) NOT NULL,
                daily_points_before INT UNSIGNED NOT NULL,
                daily_points_after INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                CONSTRAINT fk_reputation_event_detail FOREIGN KEY (event_id) REFERENCES reputation_events(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_achievements (
                achievement_key VARCHAR(80) NOT NULL PRIMARY KEY,
                label VARCHAR(190) NOT NULL,
                description VARCHAR(500) NULL,
                metric VARCHAR(50) NOT NULL,
                category VARCHAR(30) NULL,
                target_value INT UNSIGNED NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_user_achievements (
                user_id CHAR(36) NOT NULL,
                achievement_key VARCHAR(80) NOT NULL,
                progress_value INT UNSIGNED NOT NULL DEFAULT 0,
                unlocked_at DATETIME NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (user_id, achievement_key),
                CONSTRAINT fk_user_achievement_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_achievement_key FOREIGN KEY (achievement_key) REFERENCES community_achievements(achievement_key) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $achievement = $pdo->prepare(
            'INSERT INTO community_achievements
             (achievement_key, label, description, metric, category, target_value, active)
             VALUES (:achievement_key, :label, :description, :metric, :category, :target_value, 1)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label), description = VALUES(description),
                metric = VALUES(metric), category = VALUES(category),
                target_value = VALUES(target_value), active = 1'
        );

        foreach ([
            ['FIRST_IMPACT', 'Erster Beitrag', 'Erste positive Reputation erhalten.', 'POSITIVE_EVENTS', null, 1],
            ['COMMUNITY_25', 'Community-Helfer', '25 Community-Punkte erreicht.', 'CATEGORY_POINTS', 'COMMUNITY', 25],
            ['QUALITY_25', 'Qualitätsmelder', '25 Punkte für Meldungsqualität erreicht.', 'CATEGORY_POINTS', 'REPORT_QUALITY', 25],
            ['PROBLEM_SCOUT_15', 'Problemstellen-Scout', '15 Punkte für Problemstellen-Beiträge erreicht.', 'CATEGORY_POINTS', 'PROBLEM_REPORT', 15],
            ['CONSISTENT_7', 'Beständig aktiv', 'An sieben verschiedenen Tagen positive Reputation erhalten.', 'ACTIVE_DAYS', null, 7],
        ] as [$key, $label, $description, $metric, $category, $target]) {
            $achievement->execute([
                'achievement_key' => $key,
                'label' => $label,
                'description' => $description,
                'metric' => $metric,
                'category' => $category,
                'target_value' => $target,
            ]);
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS reputation_anomalies (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                anomaly_type VARCHAR(60) NOT NULL,
                severity VARCHAR(20) NOT NULL,
                risk_score SMALLINT UNSIGNED NOT NULL,
                evidence_json LONGTEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "OPEN",
                reviewed_by CHAR(36) NULL,
                resolution VARCHAR(1000) NULL,
                created_at DATETIME NOT NULL,
                reviewed_at DATETIME NULL,
                INDEX idx_reputation_anomaly_queue (status, severity, created_at),
                INDEX idx_reputation_anomaly_user (user_id, created_at),
                CONSTRAINT fk_reputation_anomaly_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_reputation_anomaly_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS reputation_admin_corrections (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                admin_user_id CHAR(36) NOT NULL,
                category VARCHAR(30) NOT NULL,
                points INT NOT NULL,
                reason VARCHAR(1000) NOT NULL,
                event_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_reputation_correction_user (user_id, created_at),
                CONSTRAINT fk_reputation_correction_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_reputation_correction_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT fk_reputation_correction_event FOREIGN KEY (event_id) REFERENCES reputation_events(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS reputation_admin_corrections');
        $pdo->exec('DROP TABLE IF EXISTS reputation_anomalies');
        $pdo->exec('DROP TABLE IF EXISTS community_user_achievements');
        $pdo->exec('DROP TABLE IF EXISTS community_achievements');
        $pdo->exec('DROP TABLE IF EXISTS reputation_event_details');
        $pdo->exec('DROP TABLE IF EXISTS reputation_daily_usage');
        $pdo->exec('DROP TABLE IF EXISTS reputation_policies');
    }
};
