<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_007_assign_admin_permissions';
    }

    public function up(PDO $pdo): void
    {
        $assign = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
             SELECT r.id, p.id, UTC_TIMESTAMP()
             FROM roles r, permissions p
             WHERE r.name = :role AND p.name = :permission'
        );

        foreach (['admin.users','admin.authorities','admin.offenses','admin.system','admin.audit'] as $permission) {
            $assign->execute(['role' => 'ADMIN', 'permission' => $permission]);
        }

        foreach (['moderation.review','moderation.action'] as $permission) {
            $assign->execute(['role' => 'COMMUNITY_MODERATOR', 'permission' => $permission]);
            $assign->execute(['role' => 'REGIONAL_MODERATOR', 'permission' => $permission]);
        }

        foreach (['authority.case.view','authority.case.reply','authority.case.export'] as $permission) {
            $assign->execute(['role' => 'AUTHORITY_USER', 'permission' => $permission]);
            $assign->execute(['role' => 'AUTHORITY_ADMIN', 'permission' => $permission]);
        }
    }

    public function down(PDO $pdo): void
    {
        // Permission assignments are retained intentionally to avoid breaking active roles on rollback.
    }
};
