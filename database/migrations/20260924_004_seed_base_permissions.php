<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_004_seed_base_permissions';
    }

    public function up(PDO $pdo): void
    {
        $roles = ['USER','COMMUNITY_MODERATOR','REGIONAL_MODERATOR','ADMIN','SUPER_ADMIN','AUTHORITY_USER','AUTHORITY_ADMIN'];
        $permissions = [
            'case.create','case.view_own','case.edit_own','case.submit','case.export',
            'evidence.upload','evidence.view_original',
            'community.post.create','community.comment.create','community.message.send',
            'moderation.review','moderation.action',
            'admin.users','admin.authorities','admin.offenses','admin.system','admin.audit',
            'authority.case.view','authority.case.reply','authority.case.export'
        ];

        $roleStmt = $pdo->prepare(
            'INSERT INTO roles (name, created_at) VALUES (:name, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );
        foreach ($roles as $role) {
            $roleStmt->execute(['name' => $role]);
        }

        $permissionStmt = $pdo->prepare(
            'INSERT INTO permissions (name, created_at) VALUES (:name, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );
        foreach ($permissions as $permission) {
            $permissionStmt->execute(['name' => $permission]);
        }

        $userPermissions = [
            'case.create','case.view_own','case.edit_own','case.submit','case.export',
            'evidence.upload','evidence.view_original',
            'community.post.create','community.comment.create','community.message.send'
        ];

        $assign = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
             SELECT r.id, p.id, UTC_TIMESTAMP()
             FROM roles r, permissions p
             WHERE r.name = :role AND p.name = :permission'
        );

        foreach ($userPermissions as $permission) {
            $assign->execute(['role' => 'USER', 'permission' => $permission]);
        }

        foreach ($permissions as $permission) {
            $assign->execute(['role' => 'SUPER_ADMIN', 'permission' => $permission]);
        }
    }

    public function down(PDO $pdo): void
    {
        // Seed data is intentionally retained to avoid removing permissions already referenced by users.
    }
};
