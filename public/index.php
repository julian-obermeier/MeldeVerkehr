<?php

declare(strict_types=1);

use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Release\ReleaseServiceFactory;
use MeldeVerkehr\Release\SecurityHeaders;

$app = require dirname(__DIR__) . '/bootstrap/app.php';

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
SecurityHeaders::send($isHttps);

$request = Request::fromGlobals();
$maintenance = ReleaseServiceFactory::maintenance($app);

$maintenanceAllowed = $request->path() === '/health'
    || $request->path() === '/health/live'
    || $request->path() === '/health/ready'
    || $request->path() === '/login'
    || $request->path() === '/logout'
    || $request->path() === '/two-factor'
    || $request->path() === '/passkey'
    || str_starts_with($request->path(), '/passkey/')
    || str_starts_with($request->path(), '/admin/system/update');

if ($maintenance->active() && !$maintenanceAllowed) {
    Response::html(
        '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Wartung – MeldeVerkehr</title></head><body><main><h1>Systemwartung</h1><p>MeldeVerkehr wird aktuell gewartet. Bitte später erneut aufrufen.</p></main></body></html>',
        503
    )->send();
    exit;
}

require dirname(__DIR__) . '/routes/web.php';

$app->run($request);
