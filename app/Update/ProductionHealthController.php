<?php

declare(strict_types=1);

namespace MeldeVerkehr\Update;

use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;

final class ProductionHealthController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function live(Request $request): Response
    {
        return Response::json([
            'success' => true,
            'data' => [
                'service' => 'MeldeVerkehr',
                'status' => 'live',
                'version' => trim((string) @file_get_contents($this->app->basePath() . '/VERSION')),
                'checked_at' => gmdate(DATE_ATOM),
            ],
            'errors' => [],
            'meta' => [],
        ]);
    }

    public function ready(Request $request): Response
    {
        $maintenance = new MaintenanceModeService($this->app->basePath());
        $health = ReleaseHealthServiceFactory::make($this->app)->check(false);

        $ready = !$maintenance->enabled() && !empty($health['ready']);
        $errors = [];

        if ($maintenance->enabled()) {
            $errors[] = [
                'code' => 'MAINTENANCE_ACTIVE',
                'message' => 'Maintenance mode is active.',
            ];
        }

        if (empty($health['ready'])) {
            $errors[] = [
                'code' => 'READINESS_FAILED',
                'message' => 'One or more production readiness checks failed.',
            ];
        }

        return Response::json([
            'success' => $ready,
            'data' => [
                'service' => 'MeldeVerkehr',
                'status' => $ready ? 'ready' : 'not_ready',
                'maintenance' => $maintenance->status(),
                'health' => $health,
            ],
            'errors' => $errors,
            'meta' => [],
        ], $ready ? 200 : 503);
    }
}
