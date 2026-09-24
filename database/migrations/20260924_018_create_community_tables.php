<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_018_create_community_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_profiles (
                user_id CHAR(36) NOT NULL PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                bio VARCHAR(1000) NULL,
                region_country CHAR(2) NOT NULL DEFAULT "DE",
                region_state VARCHAR(120) NULL,
                region_district VARCHAR(120) NULL,
                region_city VARCHAR(120) NULL,
                visibility_json LONGTEXT NOT NULL,
                leaderboard_opt_in TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT "ACTIVE",
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT fk_community_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_groups (
                id CHAR(36) NOT NULL PRIMARY KEY,
                group_type VARCHAR(20) NOT NULL,
                name VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL UNIQUE,
                description VARCHAR(1000) NULL,
                region_level VARCHAR(30) NULL,
                region_code VARCHAR(190) NULL,
                created_by_user_id CHAR(36) NULL,
                status VARCHAR(20) NOT NULL DEFAULT "ACTIVE",
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_community_group_type_status (group_type, status),
                INDEX idx_community_group_region (region_level, region_code),
                CONSTRAINT fk_community_group_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_group_memberships (
                group_id CHAR(36) NOT NULL,
                user_id CHAR(36) NOT NULL,
                role VARCHAR(30) NOT NULL DEFAULT "MEMBER",
                status VARCHAR(20) NOT NULL DEFAULT "ACTIVE",
                joined_at DATETIME NOT NULL,
                PRIMARY KEY (group_id, user_id),
                INDEX idx_group_membership_user (user_id, status),
                CONSTRAINT fk_group_membership_group FOREIGN KEY (group_id) REFERENCES community_groups(id) ON DELETE CASCADE,
                CONSTRAINT fk_group_membership_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS public_problem_areas (
                id CHAR(36) NOT NULL PRIMARY KEY,
                created_by_user_id CHAR(36) NOT NULL,
                name VARCHAR(190) NOT NULL,
                city VARCHAR(120) NULL,
                district VARCHAR(120) NULL,
                state VARCHAR(120) NULL,
                generalized_latitude DECIMAL(8,4) NULL,
                generalized_longitude DECIMAL(8,4) NULL,
                radius_m INT UNSIGNED NOT NULL DEFAULT 300,
                status VARCHAR(30) NOT NULL DEFAULT "NEW",
                moderation_status VARCHAR(20) NOT NULL DEFAULT "PENDING",
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_public_problem_geo (generalized_latitude, generalized_longitude),
                INDEX idx_public_problem_status (moderation_status, status),
                CONSTRAINT fk_public_problem_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS public_problem_observations (
                id CHAR(36) NOT NULL PRIMARY KEY,
                problem_area_id CHAR(36) NOT NULL,
                user_id CHAR(36) NOT NULL,
                observation_date DATE NOT NULL,
                offense_category VARCHAR(100) NULL,
                note VARCHAR(1000) NULL,
                status VARCHAR(20) NOT NULL DEFAULT "PUBLISHED",
                created_at DATETIME NOT NULL,
                INDEX idx_public_problem_observation (problem_area_id, observation_date),
                CONSTRAINT fk_public_problem_observation_area FOREIGN KEY (problem_area_id) REFERENCES public_problem_areas(id) ON DELETE CASCADE,
                CONSTRAINT fk_public_problem_observation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_case_releases (
                id CHAR(36) NOT NULL PRIMARY KEY,
                public_token CHAR(36) NOT NULL UNIQUE,
                user_id CHAR(36) NOT NULL,
                case_id CHAR(36) NOT NULL,
                snapshot_json LONGTEXT NOT NULL,
                snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "DRAFT",
                created_at DATETIME NOT NULL,
                published_at DATETIME NULL,
                withdrawn_at DATETIME NULL,
                INDEX idx_case_release_public (status, published_at),
                INDEX idx_case_release_owner (user_id, case_id, status),
                CONSTRAINT fk_case_release_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_case_release_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_case_release_evidence (
                release_id CHAR(36) NOT NULL,
                evidence_id CHAR(36) NOT NULL,
                order_no INT UNSIGNED NOT NULL,
                public_version_no INT UNSIGNED NOT NULL,
                sha256_snapshot CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                category_snapshot VARCHAR(40) NOT NULL,
                PRIMARY KEY (release_id, evidence_id),
                UNIQUE KEY uq_case_release_evidence_order (release_id, order_no),
                CONSTRAINT fk_case_release_evidence_release FOREIGN KEY (release_id) REFERENCES community_case_releases(id) ON DELETE CASCADE,
                CONSTRAINT fk_case_release_evidence_evidence FOREIGN KEY (evidence_id) REFERENCES evidence_items(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_posts (
                id CHAR(36) NOT NULL PRIMARY KEY,
                author_user_id CHAR(36) NOT NULL,
                group_id CHAR(36) NULL,
                problem_area_id CHAR(36) NULL,
                case_release_id CHAR(36) NULL,
                topic VARCHAR(100) NULL,
                body TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "PUBLISHED",
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_community_post_feed (status, created_at),
                INDEX idx_community_post_author (author_user_id, created_at),
                INDEX idx_community_post_group (group_id, status, created_at),
                CONSTRAINT fk_community_post_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_community_post_group FOREIGN KEY (group_id) REFERENCES community_groups(id) ON DELETE SET NULL,
                CONSTRAINT fk_community_post_problem FOREIGN KEY (problem_area_id) REFERENCES public_problem_areas(id) ON DELETE SET NULL,
                CONSTRAINT fk_community_post_release FOREIGN KEY (case_release_id) REFERENCES community_case_releases(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_comments (
                id CHAR(36) NOT NULL PRIMARY KEY,
                post_id CHAR(36) NOT NULL,
                author_user_id CHAR(36) NOT NULL,
                parent_comment_id CHAR(36) NULL,
                body VARCHAR(4000) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "PUBLISHED",
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_community_comment_post (post_id, status, created_at),
                CONSTRAINT fk_community_comment_post FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE,
                CONSTRAINT fk_community_comment_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_community_comment_parent FOREIGN KEY (parent_comment_id) REFERENCES community_comments(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_reactions (
                post_id CHAR(36) NOT NULL,
                user_id CHAR(36) NOT NULL,
                reaction VARCHAR(20) NOT NULL DEFAULT "HELPFUL",
                created_at DATETIME NOT NULL,
                PRIMARY KEY (post_id, user_id, reaction),
                CONSTRAINT fk_community_reaction_post FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE,
                CONSTRAINT fk_community_reaction_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_blocks (
                blocker_user_id CHAR(36) NOT NULL,
                blocked_user_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (blocker_user_id, blocked_user_id),
                CONSTRAINT fk_community_blocker FOREIGN KEY (blocker_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_community_blocked FOREIGN KEY (blocked_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_messages (
                id CHAR(36) NOT NULL PRIMARY KEY,
                sender_user_id CHAR(36) NOT NULL,
                recipient_user_id CHAR(36) NOT NULL,
                body_encrypted LONGTEXT NOT NULL,
                body_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "REQUEST",
                created_at DATETIME NOT NULL,
                read_at DATETIME NULL,
                INDEX idx_community_message_recipient (recipient_user_id, status, created_at),
                INDEX idx_community_message_sender (sender_user_id, created_at),
                CONSTRAINT fk_community_message_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_community_message_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_reports (
                id CHAR(36) NOT NULL PRIMARY KEY,
                reporter_user_id CHAR(36) NOT NULL,
                target_type VARCHAR(30) NOT NULL,
                target_id CHAR(36) NOT NULL,
                category VARCHAR(50) NOT NULL,
                reason VARCHAR(1000) NULL,
                status VARCHAR(20) NOT NULL DEFAULT "OPEN",
                assigned_user_id CHAR(36) NULL,
                resolution VARCHAR(1000) NULL,
                created_at DATETIME NOT NULL,
                resolved_at DATETIME NULL,
                INDEX idx_community_report_queue (status, target_type, created_at),
                CONSTRAINT fk_community_report_reporter FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_community_report_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_moderation_actions (
                id CHAR(36) NOT NULL PRIMARY KEY,
                report_id CHAR(36) NULL,
                moderator_user_id CHAR(36) NOT NULL,
                action_type VARCHAR(40) NOT NULL,
                target_type VARCHAR(30) NOT NULL,
                target_id CHAR(36) NOT NULL,
                reason VARCHAR(1000) NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_moderation_target (target_type, target_id, created_at),
                CONSTRAINT fk_moderation_action_report FOREIGN KEY (report_id) REFERENCES community_reports(id) ON DELETE SET NULL,
                CONSTRAINT fk_moderation_action_user FOREIGN KEY (moderator_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS reputation_events (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                category VARCHAR(30) NOT NULL,
                points INT NOT NULL,
                reason_key VARCHAR(80) NOT NULL,
                source_type VARCHAR(30) NULL,
                source_id CHAR(36) NULL,
                unique_key VARCHAR(190) NULL UNIQUE,
                created_at DATETIME NOT NULL,
                INDEX idx_reputation_user_category (user_id, category, created_at),
                CONSTRAINT fk_reputation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_badges (
                badge_key VARCHAR(80) NOT NULL PRIMARY KEY,
                label VARCHAR(190) NOT NULL,
                description VARCHAR(500) NULL,
                active TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_user_badges (
                user_id CHAR(36) NOT NULL,
                badge_key VARCHAR(80) NOT NULL,
                awarded_at DATETIME NOT NULL,
                source_key VARCHAR(190) NULL,
                PRIMARY KEY (user_id, badge_key),
                CONSTRAINT fk_user_badge_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_badge_badge FOREIGN KEY (badge_key) REFERENCES community_badges(badge_key) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS community_user_badges');
        $pdo->exec('DROP TABLE IF EXISTS community_badges');
        $pdo->exec('DROP TABLE IF EXISTS reputation_events');
        $pdo->exec('DROP TABLE IF EXISTS community_moderation_actions');
        $pdo->exec('DROP TABLE IF EXISTS community_reports');
        $pdo->exec('DROP TABLE IF EXISTS community_messages');
        $pdo->exec('DROP TABLE IF EXISTS community_blocks');
        $pdo->exec('DROP TABLE IF EXISTS community_reactions');
        $pdo->exec('DROP TABLE IF EXISTS community_comments');
        $pdo->exec('DROP TABLE IF EXISTS community_posts');
        $pdo->exec('DROP TABLE IF EXISTS community_case_release_evidence');
        $pdo->exec('DROP TABLE IF EXISTS community_case_releases');
        $pdo->exec('DROP TABLE IF EXISTS public_problem_observations');
        $pdo->exec('DROP TABLE IF EXISTS public_problem_areas');
        $pdo->exec('DROP TABLE IF EXISTS community_group_memberships');
        $pdo->exec('DROP TABLE IF EXISTS community_groups');
        $pdo->exec('DROP TABLE IF EXISTS community_profiles');
    }
};
