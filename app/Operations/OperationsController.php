<?php

declare(strict_types=1);

namespace MeldeVerkehr\Operations;

use MeldeVerkehr\Auth\AuthManager;
use MeldeVerkehr\Auth\AuthService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Support\View;

final class OperationsController
{
    private readonly AuthManager $auth;
    private readonly View $view;

    public function __construct(private readonly Application $app)
    {
        $this->auth = new AuthManager();
        $this->view = new View($app->basePath() . '/resources/views');
    }

    public function search(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $service = OperationsServiceFactory::search($this->app);
        $savedId = trim((string) $request->query('saved', ''));

        try {
            if ($savedId !== '') {
                $saved = $service->savedFilter($userId, $savedId);
                if ($saved === null) {
                    throw new \DomainException('Gespeicherter Filter nicht gefunden.');
                }
                $filters = $saved['filters'];
            } else {
                $filters = $this->filterInputFromQuery($request);
            }

            $result = $service->search($userId, $filters);
        } catch (\InvalidArgumentException|\DomainException $e) {
            $_SESSION['operations_error'] = $e->getMessage();
            $result = $service->search($userId, []);
        }

        return Response::html($this->view->render('operations/search', [
            'result' => $result,
            'savedFilters' => $service->savedFilters($userId),
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('operations_message'),
            'error' => $this->pullFlash('operations_error'),
        ]));
    }

    public function saveFilter(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->validCsrf($request)) {
            return Response::redirect('/search');
        }

        try {
            OperationsServiceFactory::search($this->app)->saveFilter(
                $userId,
                (string) $request->input('name', ''),
                $this->filterInputFromBody($request)
            );
            $_SESSION['operations_message'] = 'Filter gespeichert.';
        } catch (\InvalidArgumentException $e) {
            $_SESSION['operations_error'] = $e->getMessage();
        }

        return Response::redirect('/search');
    }

    public function deleteFilter(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->validCsrf($request)) {
            return Response::redirect('/search');
        }

        try {
            OperationsServiceFactory::search($this->app)->deleteFilter(
                $userId,
                (string) $request->route('id', '')
            );
            $_SESSION['operations_message'] = 'Filter gelöscht.';
        } catch (\DomainException $e) {
            $_SESSION['operations_error'] = $e->getMessage();
        }

        return Response::redirect('/search');
    }

    public function documents(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        return Response::html($this->view->render('operations/documents', [
            'items' => OperationsServiceFactory::documents($this->app)->list(
                $userId,
                $this->nullable($request->query('type')),
                $this->nullable($request->query('q'))
            ),
            'type' => (string) $request->query('type', ''),
            'query' => (string) $request->query('q', ''),
        ]));
    }

    public function notifications(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $service = OperationsServiceFactory::notifications($this->app);
        $service->syncForUser($userId);

        return Response::html($this->view->render('operations/notifications', [
            'notifications' => $service->list(
                $userId,
                (string) $request->query('unread', '') === '1'
            ),
            'unreadCount' => $service->unreadCount($userId),
            'csrf' => Csrf::token(),
        ]));
    }

    public function markNotification(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->validCsrf($request)) {
            return Response::redirect('/notifications');
        }

        try {
            OperationsServiceFactory::notifications($this->app)->markRead(
                $userId,
                (string) $request->route('id', '')
            );
        } catch (\DomainException $e) {
            $_SESSION['operations_error'] = $e->getMessage();
        }

        return Response::redirect('/notifications');
    }

    public function markAllNotifications(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->validCsrf($request)) {
            return Response::redirect('/notifications');
        }

        OperationsServiceFactory::notifications($this->app)->markAllRead($userId);

        return Response::redirect('/notifications');
    }

    public function notificationSettings(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $notifications = OperationsServiceFactory::notifications($this->app);
        $push = OperationsServiceFactory::push($this->app);

        return Response::html($this->view->render('operations/notification-settings', [
            'preferences' => $notifications->preferences($userId),
            'pushConfigured' => $push->configured(),
            'pushSubscriptions' => $push->activeCount($userId),
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('operations_message'),
            'error' => $this->pullFlash('operations_error'),
        ]));
    }

    public function saveNotificationSettings(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->validCsrf($request)) {
            return Response::redirect('/notifications/settings');
        }

        $inApp = $request->input('in_app', []);
        $email = $request->input('email', []);
        $push = $request->input('push', []);
        $inApp = is_array($inApp) ? $inApp : [];
        $email = is_array($email) ? $email : [];
        $push = is_array($push) ? $push : [];

        $service = OperationsServiceFactory::notifications($this->app);
        foreach ($service->eventKeys() as $eventKey) {
            $service->savePreference(
                $userId,
                $eventKey,
                isset($inApp[$eventKey]),
                isset($email[$eventKey]),
                isset($push[$eventKey])
            );
        }

        $_SESSION['operations_message'] = 'Benachrichtigungseinstellungen gespeichert.';

        return Response::redirect('/notifications/settings');
    }

    public function pushKey(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $key = OperationsServiceFactory::push($this->app)->publicKey();
        if ($key === '') {
            return Response::json([
                'success' => false,
                'data' => null,
                'errors' => [['code' => 'PUSH_NOT_CONFIGURED', 'message' => 'Push ist nicht konfiguriert.']],
                'meta' => [],
            ], 503);
        }

        return Response::json([
            'success' => true,
            'data' => ['public_key' => $key],
            'errors' => [],
            'meta' => [],
        ]);
    }

    public function registerPush(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            return Response::json([
                'success' => false,
                'data' => null,
                'errors' => [['code' => 'CSRF', 'message' => 'Sitzung abgelaufen.']],
                'meta' => [],
            ], 419);
        }

        try {
            OperationsServiceFactory::push($this->app)->register(
                $userId,
                (string) $request->input('endpoint', ''),
                $this->nullable($request->input('p256dh')),
                $this->nullable($request->input('auth')),
                $this->nullable($request->server('HTTP_USER_AGENT', ''))
            );
        } catch (\InvalidArgumentException $e) {
            return Response::json([
                'success' => false,
                'data' => null,
                'errors' => [['code' => 'VALIDATION', 'message' => $e->getMessage()]],
                'meta' => [],
            ], 422);
        }

        return Response::json([
            'success' => true,
            'data' => ['registered' => true],
            'errors' => [],
            'meta' => [],
        ]);
    }

    public function disablePush(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            return Response::json([
                'success' => false,
                'data' => null,
                'errors' => [['code' => 'CSRF', 'message' => 'Sitzung abgelaufen.']],
                'meta' => [],
            ], 419);
        }

        OperationsServiceFactory::push($this->app)->revokeAll($userId);

        return Response::json([
            'success' => true,
            'data' => ['revoked' => true],
            'errors' => [],
            'meta' => [],
        ]);
    }

    public function pushLatest(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return Response::json([
                'success' => false,
                'data' => null,
                'errors' => [['code' => 'AUTH_REQUIRED', 'message' => 'Anmeldung erforderlich.']],
                'meta' => [],
            ], 401);
        }

        $item = OperationsServiceFactory::notifications($this->app)->latestPushPayload($userId);

        return Response::json([
            'success' => true,
            'data' => $item,
            'errors' => [],
            'meta' => [],
        ], 200, ['Cache-Control' => 'private, no-store, max-age=0']);
    }

    public function createExport(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->validCsrf($request)) {
            return Response::redirect('/search');
        }

        try {
            $export = OperationsServiceFactory::exports($this->app)->createCaseExport(
                $userId,
                (string) $request->input('format', 'csv'),
                (string) $request->input('include_sensitive', '') === '1',
                $this->filterInputFromBody($request)
            );
            $_SESSION['operations_message'] = 'Export erstellt: ' . $export['filename'];
            return Response::redirect('/documents');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $_SESSION['operations_error'] = $e->getMessage();
            return Response::redirect('/search');
        }
    }

    public function exportBinary(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        try {
            $file = OperationsServiceFactory::exports($this->app)->binary(
                $userId,
                (string) $request->route('id', '')
            );

            $safeFilename = preg_replace(
                '/[^A-Za-z0-9._-]/',
                '_',
                (string) $file['filename']
            ) ?: 'export.dat';

            return Response::binary(
                (string) $file['body'],
                (string) $file['mime_type'],
                200,
                [
                    'Content-Disposition' => 'attachment; filename="' . $safeFilename . '"',
                    'ETag' => '"' . $file['sha256'] . '"',
                    'Cache-Control' => 'private, no-store, max-age=0',
                ]
            );
        } catch (\DomainException $e) {
            return Response::html('<h1>404</h1><p>Export nicht gefunden.</p>', 404);
        }
    }

    public function retention(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $service = OperationsServiceFactory::retention($this->app);

        return Response::html($this->view->render('operations/retention', [
            'overview' => $service->overview($userId),
            'deletionPreview' => $service->accountDeletionPreview($userId),
        ]));
    }

    private function filterInputFromQuery(Request $request): array
    {
        return [
            'q' => $request->query('q'),
            'status' => $request->query('status'),
            'city' => $request->query('city'),
            'authority_id' => $request->query('authority_id'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];
    }

    private function filterInputFromBody(Request $request): array
    {
        return [
            'q' => $request->input('q'),
            'status' => $request->input('status'),
            'city' => $request->input('city'),
            'authority_id' => $request->input('authority_id'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];
    }

    private function validCsrf(Request $request): bool
    {
        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['operations_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return false;
        }

        return true;
    }

    private function requireUser(): string|Response
    {
        $userId = $this->auth->id();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $user = (new AuthService($this->app->database()))->findById($userId);
        if ($user === null || $user['status'] !== 'ACTIVE') {
            $this->auth->logout();
            return Response::redirect('/login');
        }

        if ($user['email_verified_at'] === null) {
            return Response::redirect('/verify-email/pending');
        }

        return $userId;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function pullFlash(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return is_string($value) ? $value : null;
    }
}
