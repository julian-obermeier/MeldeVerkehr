<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_027_create_community_social_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_follows (
                follower_user_id CHAR(36) NOT NULL,
                followed_user_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (follower_user_id, followed_user_id),
                INDEX idx_community_followed (followed_user_id, created_at),
                CONSTRAINT fk_community_follow_follower FOREIGN KEY (follower_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_community_follow_followed FOREIGN KEY (followed_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_favorites (
                user_id CHAR(36) NOT NULL,
                post_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (user_id, post_id),
                INDEX idx_community_favorite_post (post_id, created_at),
                CONSTRAINT fk_community_favorite_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_community_favorite_post FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS community_group_messages (
                id CHAR(36) NOT NULL PRIMARY KEY,
                group_id CHAR(36) NOT NULL,
                sender_user_id CHAR(36) NOT NULL,
                body_encrypted LONGTEXT NOT NULL,
                body_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "PUBLISHED",
                created_at DATETIME NOT NULL,
                INDEX idx_community_group_message (group_id, status, created_at),
                INDEX idx_community_group_message_sender (sender_user_id, created_at),
                CONSTRAINT fk_community_group_message_group FOREIGN KEY (group_id) REFERENCES community_groups(id) ON DELETE CASCADE,
                CONSTRAINT fk_community_group_message_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS community_group_messages');
        $pdo->exec('DROP TABLE IF EXISTS community_favorites');
        $pdo->exec('DROP TABLE IF EXISTS community_follows');
    }
};
