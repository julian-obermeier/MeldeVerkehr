<?php

declare(strict_types=1);

namespace MeldeVerkehr\Database;

use PDO;

interface MigrationInterface
{
    public function id(): string;

    public function up(PDO $pdo): void;

    public function down(PDO $pdo): void;
}
