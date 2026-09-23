<?php

declare(strict_types=1);

use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Install\InstallerController;
use MeldeVerkehr\Install\InstallerService;

/** @var Application $app */

$installer = new InstallerController($app->basePath());
$installerService = new InstallerService($app->basePath());

$app->router()->get('/install', [$installer, 'index']);
$app->router()->post('/install/database', [$installer, 'database']);
$app->router()->get('/install/admin', [$installer, 'adminForm']);
$app->router()->post('/install/admin', [$installer, 'install']);
$app->router()->get('/install/complete', [$installer, 'complete']);

$app->router()->get('/', static function (Request $request) use ($installerService): Response {
    if (!$installerService->isInstalled()) {
        return Response::redirect('/install');
    }

    return Response::html(
        '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MeldeVerkehr</title></head><body><main><h1>MeldeVerkehr</h1><p>Die technische Basis ist aktiv.</p></main></body></html>'
    );
});

$app->router()->get('/health', static function (Request $request) use ($installerService): Response {
    return Response::json([
        'success' => true,
        'data' => [
            'service' => 'MeldeVerkehr',
            'status' => 'ok',
            'installed' => $installerService->isInstalled(),
            'version' => trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')),
        ],
        'errors' => [],
        'meta' => [],
    ]);
});
