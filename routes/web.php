<?php

declare(strict_types=1);

use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;

/** @var Application $app */

$app->router()->get('/', static function (Request $request): Response {
    return Response::html(
        '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MeldeVerkehr</title></head><body><main><h1>MeldeVerkehr</h1><p>Die technische Basis ist aktiv.</p></main></body></html>'
    );
});

$app->router()->get('/health', static function (Request $request): Response {
    return Response::json([
        'success' => true,
        'data' => [
            'service' => 'MeldeVerkehr',
            'status' => 'ok',
            'version' => trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')),
        ],
        'errors' => [],
        'meta' => [],
    ]);
});
