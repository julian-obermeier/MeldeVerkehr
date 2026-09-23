<?php

declare(strict_types=1);

namespace MeldeVerkehr\Admin;

use MeldeVerkehr\Audit\AuditContext;
use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthManager;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Support\View;
use PDO;

final class AdminController
{
    private readonly AuthManager $auth;
    private readonly View $view;

    public function __construct(private readonly Application $app)
    {
        $this->auth = new AuthManager();
        $this->view = new View($app->basePath() . '/resources/views');
    }

    public function index(Request $request): Response
    {
        $userId = $this->auth->id();

        if ($userId === null) {
            return Response::redirect('/login');
        }

        $permissions = new PermissionService($this->app->database());

        if (!$permissions->can($userId, 'admin.system')) {
            return Response::html(
                '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>403</title></head><body><h1>403</h1><p>Keine Berechtigung.</p></body></html>',
                403
            );
        }

        $audit = new AuditLogger(
            $this->app->database(),
            (string) $this->app->config()->get('app.key', 'meldeverkehr')
        );
        $audit->log(
            'ADMIN_DASHBOARD_VIEW',
            'admin',
            'system',
            'USER',
            $userId,
            AuditContext::requestMetadata((string) $this->app->config()->get('app.key', 'meldeverkehr'))
        );

        return Response::html($this->view->render('admin/index', [
            'version' => trim((string) @file_get_contents($this->app->basePath() . '/VERSION')),
            'phpVersion' => PHP_VERSION,
            'databaseVersion' => (string) $this->app->database()->query('SELECT VERSION()')->fetchColumn(),
            'userCount' => (int) $this->app->database()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'jobCounts' => $this->jobCounts($this->app->database()),
            'cronRuns' => $this->recentCronRuns($this->app->database()),
            'auditIntegrity' => $audit->verifyChain(),
        ]));
    }

    private function jobCounts(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT status, COUNT(*) AS count FROM jobs GROUP BY status ORDER BY status'
        )->fetchAll();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['count'];
        }

        return $counts;
    }

    private function recentCronRuns(PDO $pdo): array
    {
        $stmt = $pdo->query(
            'SELECT task, status, started_at, finished_at, processed_count, error_count
             FROM cron_runs ORDER BY id DESC LIMIT 10'
        );

        return $stmt->fetchAll();
    }
}
