<?php

declare(strict_types=1);

use MeldeVerkehr\Support\Env;

return [
    'name' => Env::get('APP_NAME', 'MeldeVerkehr'),
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => Env::get('APP_URL', ''),
    'key' => Env::get('APP_KEY', ''),
    'timezone' => Env::get('APP_TIMEZONE', 'Europe/Berlin'),
    'locale' => Env::get('APP_LOCALE', 'de'),
    'installed' => Env::bool('APP_INSTALLED', false),
];
