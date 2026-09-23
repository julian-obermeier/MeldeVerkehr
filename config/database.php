<?php

declare(strict_types=1);

use MeldeVerkehr\Support\Env;

return [
    'driver' => 'mysql',
    'host' => Env::get('DB_HOST', 'localhost'),
    'port' => (int) (Env::get('DB_PORT', '3306') ?? 3306),
    'database' => Env::get('DB_DATABASE', ''),
    'username' => Env::get('DB_USERNAME', ''),
    'password' => Env::get('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];
