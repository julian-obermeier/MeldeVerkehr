<?php

declare(strict_types=1);

namespace MeldeVerkehr\Release;

use MeldeVerkehr\Auth\AuthManager;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Support\View;

final class ReleaseAdminController
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
        $userId = $this->requireAdmin();

        if ($userId instanceof Response) {
            return $userId;
        }

        $current = trim((string) @file_get_contents($this->app->basePath() . '/VERSION'));
        $lastPath = $this->app->basePath() . '/storage/app/last-version.txt';
        $last = is_file($lastPath) ? trim((string) file_get_contents($lastPath)) : '';

        return Response::html($this->view->render('admin/release', [
            'currentVersion' => $current,
            'lastVersion' => $last,
            'readiness' => ReleaseServiceFactory::readiness($this->app)->check(),
            'backups' => ReleaseServiceFactory::backups($this->app)->list(),
            'updates' => ReleaseServiceFactory::updates($this->app)->history(),
            'maintenance' => ReleaseServiceFactory::maintenance($this->app)->status(),
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('release_message'),
            'error' => $this->pullFlash('release_error'),
        ]));
    }

    public function backup(Request $request): Response
    {
        $userId = $this->requireAdmin();

        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request)) {
            return Response::redirect('/admin/system/update');
        }

        try {
            $backup = ReleaseServiceFactory::backups($this->app)->create(
                $userId,
                (string) $request->input('database_only', '') !== '1'
            );
            $_SESSION['release_message'] = 'Backup erstellt: ' . $backup['id'];
        } catch (\Throwable $e) {
            $_SESSION['release_error'] = $e->getMessage();
        }

        return Response::redirect('/admin/system/update');
    }

    public function verify(Request $request): Response
    {
        $userId = $this->requireAdmin();

        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request)) {
            return Response::redirect('/admin/system/update');
        }

        try {
            $result = ReleaseServiceFactory::backups($this->app)->verify(
                (string) $request->route('id', '')
            );
            $_SESSION['release_message'] =
                'Backup verifiziert: ' . $result['runtime_files_verified'] .
                ' Runtime-Dateien geprüft.';
        } catch (\Throwable $e) {
            $_SESSION['release_error'] = $e->getMessage();
        }

        return Response::redirect('/admin/system/update');
    }

    public function update(Request $request): Response
    {
        $userId = $this->requireAdmin();

        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request)) {
            return Response::redirect('/admin/system/update');
        }

        try {
            $from = trim((string) $request->input('from_version', ''));
            $to = trim((string) @file_get_contents($this->app->basePath() . '/VERSION'));

            $result = ReleaseServiceFactory::updates($this->app)->run(
                $from,
                $to,
                $userId
            );

            $_SESSION['release_message'] =
                'Update abgeschlossen. Backup: ' . $result['backup_id'] .
                '; Migrationen: ' . count($result['migrations_ran']);
        } catch (\Throwable $e) {
            $_SESSION['release_error'] =
                $e->getMessage() .
                ' Der Maintenance-Modus bleibt bei fehlgeschlagenem Update aktiv.';
        }

        return Response::redirect('/admin/system/update');
    }

    public function maintenanceOff(Request $request): Response
    {
        $userId = $this->requireAdmin();

        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request)) {
            return Response::redirect('/admin/system/update');
        }

        try {
            ReleaseServiceFactory::maintenance($this->app)->disable();
            $_SESSION['release_message'] = 'Maintenance-Modus deaktiviert.';
        } catch (\Throwable $e) {
            $_SESSION['release_error'] = $e->getMessage();
        }

        return Response::redirect('/admin/system/update');
    }

    private function requireAdmin(): string|Response
    {
        $userId = $this->auth->id();

        if ($userId === null) {
            return Response::redirect('/login');
        }

        if (!(new PermissionService($this->app->database()))->can($userId, 'admin.system')) {
            return Response::html('<h1>403</h1><p>Keine Berechtigung.</p>', 403);
        }

        return $userId;
    }

    private function csrf(Request $request): bool
    {
        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['release_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return false;
        }

        return true;
    }

    private function pullFlash(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return is_string($value) ? $value : null;
    }
}
