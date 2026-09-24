<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_019_seed_community_badges';
    }

    public function up(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO community_badges
             (badge_key, label, description, active)
             VALUES (:badge_key, :label, :description, 1)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                description = VALUES(description),
                active = 1'
        );

        foreach ([
            ['COMMUNITY_STARTER', 'Community-Starter', 'Erste qualitätsgewichtete Community-Punkte erreicht.'],
            ['COMMUNITY_HELPER', 'Hilfreich', 'Mindestens 10 Community-Reputationspunkte erreicht.'],
            ['COMMUNITY_CONTRIBUTOR', 'Beitragende Person', 'Mindestens 50 Community-Reputationspunkte erreicht.'],
        ] as [$key, $label, $description]) {
            $stmt->execute([
                'badge_key' => $key,
                'label' => $label,
                'description' => $description,
            ]);
        }
    }

    public function down(PDO $pdo): void
    {
        // Badge-Stammdaten bleiben absichtlich bestehen, falls bereits Auszeichnungen darauf verweisen.
    }
};
