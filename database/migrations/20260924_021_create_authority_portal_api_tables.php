<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_021_create_authority_portal_api_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS authority_user_scopes (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id CHAR(36) NOT NULL,
                authority_id CHAR(36) NOT NULL,
                scope_role VARCHAR(30) NOT NULL DEFAULT "AUTHORITY_USER",
                status VARCHAR(20) NOT NULL DEFAULT "ACTIVE",
                created_by_user_id CHAR(36) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_authority_user_scope (user_id, authority_id),
                INDEX idx_authority_scope_authority (authority_id, status, scope_role),
                INDEX idx_authority_scope_user (user_id, status),
                CONSTRAINT fk_authority_scope_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_authority_scope_authority FOREIGN KEY (authority_id) REFERENCES authorities(id) ON DELETE CASCADE,
                CONSTRAINT fk_authority_scope_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS authority_api_tokens (
                id CHAR(36) NOT NULL PRIMARY KEY,
                authority_id CHAR(36) NOT NULL,
                user_id CHAR(36) NOT NULL,
                name VARCHAR(120) NOT NULL,
                token_prefix VARCHAR(24) NOT NULL,
                token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
                scopes_json LONGTEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "ACTIVE",
                expires_at DATETIME NULL,
                last_used_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                revoked_at DATETIME NULL,
                INDEX idx_authority_api_authority (authority_id, status, expires_at),
                INDEX idx_authority_api_user (user_id, status),
                CONSTRAINT fk_authority_api_authority FOREIGN KEY (authority_id) REFERENCES authorities(id) ON DELETE CASCADE,
                CONSTRAINT fk_authority_api_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS authority_portal_inquiries (
                id CHAR(36) NOT NULL PRIMARY KEY,
                case_id CHAR(36) NOT NULL,
                authority_id CHAR(36) NOT NULL,
                created_by_user_id CHAR(36) NOT NULL,
                inquiry_type VARCHAR(40) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body_encrypted LONGTEXT NOT NULL,
                body_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "OPEN",
                due_at DATETIME NULL,
                case_task_id CHAR(36) NULL,
                created_at DATETIME NOT NULL,
                answered_at DATETIME NULL,
                INDEX idx_authority_inquiry_authority (authority_id, status, created_at),
                INDEX idx_authority_inquiry_case (case_id, status, created_at),
                CONSTRAINT fk_authority_inquiry_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_authority_inquiry_authority FOREIGN KEY (authority_id) REFERENCES authorities(id) ON DELETE CASCADE,
                CONSTRAINT fk_authority_inquiry_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT fk_authority_inquiry_task FOREIGN KEY (case_task_id) REFERENCES case_tasks(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS authority_holder_records (
                id CHAR(36) NOT NULL PRIMARY KEY,
                authority_id CHAR(36) NOT NULL,
                case_id CHAR(36) NOT NULL,
                holder_name_encrypted TEXT NOT NULL,
                holder_address_encrypted LONGTEXT NOT NULL,
                date_of_birth_encrypted TEXT NULL,
                metadata_encrypted LONGTEXT NULL,
                payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                key_version INT UNSIGNED NOT NULL DEFAULT 1,
                created_by_user_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_authority_holder_case (authority_id, case_id),
                INDEX idx_authority_holder_authority (authority_id, updated_at),
                CONSTRAINT fk_authority_holder_authority FOREIGN KEY (authority_id) REFERENCES authorities(id) ON DELETE CASCADE,
                CONSTRAINT fk_authority_holder_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE RESTRICT,
                CONSTRAINT fk_authority_holder_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $permissionStmt = $pdo->prepare(
            'INSERT INTO permissions (name, created_at)
             VALUES (:name, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );
        foreach ([
            'authority.case.view',
            'authority.case.reply',
            'authority.case.export',
            'authority.holder.read',
            'authority.holder.write',
            'authority.users.manage',
            'authority.tokens.manage',
        ] as $permission) {
            $permissionStmt->execute(['name' => $permission]);
        }

        $assign = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
             SELECT r.id, p.id, UTC_TIMESTAMP()
             FROM roles r, permissions p
             WHERE r.name = :role AND p.name = :permission'
        );

        foreach (['authority.case.view','authority.case.reply','authority.case.export'] as $permission) {
            $assign->execute(['role' => 'AUTHORITY_USER', 'permission' => $permission]);
        }

        foreach ([
            'authority.case.view','authority.case.reply','authority.case.export',
            'authority.holder.read','authority.holder.write',
            'authority.users.manage','authority.tokens.manage'
        ] as $permission) {
            $assign->execute(['role' => 'AUTHORITY_ADMIN', 'permission' => $permission]);
        }

        foreach ([
            'authority.case.view','authority.case.reply','authority.case.export',
            'authority.holder.read','authority.holder.write',
            'authority.users.manage','authority.tokens.manage'
        ] as $permission) {
            $assign->execute(['role' => 'SUPER_ADMIN', 'permission' => $permission]);
        }
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS authority_holder_records');
        $pdo->exec('DROP TABLE IF EXISTS authority_portal_inquiries');
        $pdo->exec('DROP TABLE IF EXISTS authority_api_tokens');
        $pdo->exec('DROP TABLE IF EXISTS authority_user_scopes');
    }
};
