<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_024_create_moderation_safety_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_activity_signals (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                action_type VARCHAR(30) NOT NULL,
                target_type VARCHAR(30) NULL,
                target_id CHAR(36) NULL,
                content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                metadata_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_activity_user_action_created (user_id, action_type, created_at),
                INDEX idx_activity_content_created (content_hash, created_at),
                CONSTRAINT fk_activity_signal_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_abuse_flags (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                signal_type VARCHAR(50) NOT NULL,
                severity VARCHAR(20) NOT NULL,
                risk_score SMALLINT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "OPEN",
                related_report_id CHAR(36) NULL,
                evidence_json LONGTEXT NOT NULL,
                reviewed_by CHAR(36) NULL,
                reviewed_at DATETIME NULL,
                resolution VARCHAR(1000) NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_abuse_flags_queue (status, severity, created_at),
                INDEX idx_abuse_flags_user (user_id, created_at),
                CONSTRAINT fk_abuse_flag_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_abuse_flag_report FOREIGN KEY (related_report_id) REFERENCES community_reports(id) ON DELETE SET NULL,
                CONSTRAINT fk_abuse_flag_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_user_moderation_state (
                user_id CHAR(36) NOT NULL PRIMARY KEY,
                posting_restricted_until DATETIME NULL,
                messaging_restricted_until DATETIME NULL,
                reporting_restricted_until DATETIME NULL,
                reason VARCHAR(1000) NULL,
                updated_by CHAR(36) NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT fk_mod_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_mod_state_actor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_report_risk (
                report_id CHAR(36) NOT NULL PRIMARY KEY,
                risk_score SMALLINT UNSIGNED NOT NULL,
                indicators_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                CONSTRAINT fk_report_risk_report FOREIGN KEY (report_id) REFERENCES community_reports(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_moderation_action_snapshots (
                action_id CHAR(36) NOT NULL PRIMARY KEY,
                before_state_json LONGTEXT NOT NULL,
                after_state_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                CONSTRAINT fk_mod_action_snapshot FOREIGN KEY (action_id) REFERENCES community_moderation_actions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_moderation_appeals (
                id CHAR(36) NOT NULL PRIMARY KEY,
                report_id CHAR(36) NOT NULL,
                appellant_user_id CHAR(36) NOT NULL,
                reason VARCHAR(4000) NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT "OPEN",
                assigned_user_id CHAR(36) NULL,
                resolution VARCHAR(4000) NULL,
                created_at DATETIME NOT NULL,
                resolved_at DATETIME NULL,
                UNIQUE KEY uq_moderation_appeal_report_user (report_id, appellant_user_id),
                INDEX idx_moderation_appeal_queue (status, created_at),
                CONSTRAINT fk_moderation_appeal_report FOREIGN KEY (report_id) REFERENCES community_reports(id) ON DELETE CASCADE,
                CONSTRAINT fk_moderation_appeal_user FOREIGN KEY (appellant_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_moderation_appeal_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_moderation_escalations (
                id CHAR(36) NOT NULL PRIMARY KEY,
                source_type VARCHAR(24) NOT NULL,
                source_id CHAR(36) NOT NULL,
                created_by_user_id CHAR(36) NOT NULL,
                priority VARCHAR(20) NOT NULL,
                reason VARCHAR(4000) NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT "OPEN",
                assigned_user_id CHAR(36) NULL,
                resolution VARCHAR(4000) NULL,
                created_at DATETIME NOT NULL,
                resolved_at DATETIME NULL,
                INDEX idx_moderation_escalation_queue (status, priority, created_at),
                INDEX idx_moderation_escalation_source (source_type, source_id),
                CONSTRAINT fk_moderation_escalation_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT fk_moderation_escalation_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS community_moderation_escalations');
        $pdo->exec('DROP TABLE IF EXISTS community_moderation_appeals');
        $pdo->exec('DROP TABLE IF EXISTS community_moderation_action_snapshots');
        $pdo->exec('DROP TABLE IF EXISTS community_report_risk');
        $pdo->exec('DROP TABLE IF EXISTS community_user_moderation_state');
        $pdo->exec('DROP TABLE IF EXISTS community_abuse_flags');
        $pdo->exec('DROP TABLE IF EXISTS community_activity_signals');
    }
};
