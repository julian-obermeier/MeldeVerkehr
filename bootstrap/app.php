<?php

declare(strict_types=1);

use MeldeVerkehr\Config\Config;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Routing\Router;
use MeldeVerkehr\Support\Env;

$basePath = dirname(__DIR__);

require $basePath . '/bootstrap/autoload.php';

Env::load($basePath . '/.env');

$config = new Config($basePath . '/config');
$config->load();

date_default_timezone_set((string) $config->get('app.timezone', 'Europe/Berlin'));

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    session_name('meldeverkehr_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

return new Application(
    new Router(),
    $config,
    $basePath,
    (bool) $config->get('app.debug', false)
);
