<?php

declare(strict_types=1);

use MeldeVerkehr\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '20260924_010_seed_unclassified_offense';
    }

    public function up(PDO $pdo): void
    {
        $categories = [
            ['SIDEWALK', 'Gehweg', 10],
            ['BIKE_PATH', 'Radweg', 20],
            ['NO_STOPPING', 'Haltverbot', 30],
            ['NO_PARKING', 'Parkverbot', 40],
            ['FIRE_ACCESS', 'Feuerwehrzufahrt', 50],
            ['INTERSECTION', 'Kreuzungsbereich', 60],
            ['DRIVEWAY', 'Einfahrt', 70],
            ['RESIDENT_PARKING', 'Bewohnerparken', 80],
            ['PARKING_DISC', 'Parkscheibe', 90],
            ['ACCESSIBLE_PARKING', 'Schwerbehindertenparkplatz', 100],
            ['LOADING_ZONE', 'Ladezone', 110],
            ['PEDESTRIAN_ZONE', 'Fußgängerzone', 120],
            ['SHOULDER', 'Seitenstreifen', 130],
            ['OTHER', 'Sonstiges', 999],
        ];

        $categoryStmt = $pdo->prepare(
            'INSERT INTO offense_categories (category_key, label, sort_order, active)
             VALUES (:key, :label, :sort_order, 1)
             ON DUPLICATE KEY UPDATE label = VALUES(label), sort_order = VALUES(sort_order)'
        );

        foreach ($categories as [$key, $label, $sortOrder]) {
            $categoryStmt->execute([
                'key' => $key,
                'label' => $label,
                'sort_order' => $sortOrder,
            ]);
        }

        $offenseId = '00000000-0000-4000-8000-000000000001';
        $versionId = '00000000-0000-4000-8000-000000000002';

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO offenses
             (id, stable_key, category_key, active, created_at, updated_at)
             VALUES (:id, "UNCLASSIFIED_PARKING", "OTHER", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute(['id' => $offenseId]);

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO offense_versions
             (id, offense_id, version, code, title, description, legal_reference, fine_amount, points,
              duration_requirement, requires_sign, requires_duration, supports_obstruction,
              supports_endangerment, supports_damage, valid_from, valid_until, created_at)
             VALUES
             (:id, :offense_id, 1, NULL, "Noch nicht zugeordnet",
              "Interner neutraler Platzhalter für Entwürfe. Vor Versand muss ein konkreter Tatbestand ausgewählt werden.",
              NULL, NULL, NULL, NULL, 0, 0, 1, 1, 1, NULL, NULL, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'id' => $versionId,
            'offense_id' => $offenseId,
        ]);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("DELETE FROM offense_versions WHERE id = '00000000-0000-4000-8000-000000000002'");
        $pdo->exec("DELETE FROM offenses WHERE id = '00000000-0000-4000-8000-000000000001'");
    }
};
