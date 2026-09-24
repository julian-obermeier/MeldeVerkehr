<?php

declare(strict_types=1);

namespace MeldeVerkehr\Release;

use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;

final class ReleaseHealthController
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
        $readiness = ReleaseServiceFactory::readiness($this->app)->check();
        $maintenance = ReleaseServiceFactory::maintenance($this->app)->status();

        $ready = !empty($readiness['ready']) && empty($maintenance['active']);

        $errors = [];
        if (!empty($maintenance['active'])) {
            $errors[] = [
                'code' => 'MAINTENANCE_ACTIVE',
                'message' => 'Maintenance mode is active.',
            ];
        }

        if (empty($readiness['ready'])) {
            $errors[] = [
                'code' => 'READINESS_FAILED',
                'message' => 'One or more release-readiness checks failed.',
            ];
        }

        return Response::json([
            'success' => $ready,
            'data' => [
                'service' => 'MeldeVerkehr',
                'status' => $ready ? 'ready' : 'not_ready',
                'version' => trim((string) @file_get_contents($this->app->basePath() . '/VERSION')),
                'maintenance' => $maintenance,
                'readiness' => $readiness,
            ],
            'errors' => $errors,
            'meta' => [],
        ], $ready ? 200 : 503);
    }
}
