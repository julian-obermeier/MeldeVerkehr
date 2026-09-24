<?php

declare(strict_types=1);

use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Update\MaintenanceModeService;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$request = Request::fromGlobals();

$maintenance = new MaintenanceModeService(dirname(__DIR__));

$healthPaths = ['/health', '/health/live', '/health/ready'];

if ($maintenance->enabled() && !in_array($request->path(), $healthPaths, true)) {
    $status = $maintenance->status();

    Response::html(
        '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Wartung – MeldeVerkehr</title></head><body><main><h1>MeldeVerkehr wird gewartet</h1><p>Die Anwendung ist vorübergehend nicht verfügbar.</p><p>Bitte versuche es später erneut.</p></main></body></html>',
        503
    )->send();

    exit;
}

require dirname(__DIR__) . '/routes/web.php';

$app->run($request);
