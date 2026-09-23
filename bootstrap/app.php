<?php

declare(strict_types=1);

use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Routing\Router;
use MeldeVerkehr\Support\Env;

$basePath = dirname(__DIR__);

require $basePath . '/bootstrap/autoload.php';

Env::load($basePath . '/.env');

date_default_timezone_set(Env::get('APP_TIMEZONE', 'Europe/Berlin') ?? 'Europe/Berlin');

$debug = Env::bool('APP_DEBUG', false);

return new Application(
    new Router(),
    $basePath,
    $debug
);
