<?php

declare(strict_types=1);

namespace MeldeVerkehr\Dispatch;

use MeldeVerkehr\Auth\AuthManager;
use MeldeVerkehr\Auth\AuthService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Support\View;

final class DispatchController
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
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = (string) $request->route('id', '');
        $service = DispatchServiceFactory::make($this->app);
        $review = null;
        $latest = null;
        $reviewError = null;

        try {
            $review = $service->review($userId, $caseId);
        } catch (\DomainException $e) {
            $reviewError = $e->getMessage();
        }

        try {
            $latest = $service->latestForCase($userId, $caseId);
        } catch (\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            if ($review === null) {
                return Response::html('<h1>404</h1><p>Vorgang nicht gefunden.</p>', 404);
            }
        }

        return Response::html($this->view->render('dispatch/review', [
            'caseId' => $caseId,
            'review' => $review,
            'latest' => $latest,
            'reviewError' => $reviewError,
            'transportMode' => (string) $this->app->config()->get('dispatch.transport', 'dry_run'),
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('dispatch_message'),
            'error' => $this->pullFlash('dispatch_error'),
        ]));
    }

    public function queue(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = (string) $request->route('id', '');

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['dispatch_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/dispatch');
        }

        try {
            $result = DispatchServiceFactory::make($this->app)->queueDispatch(
                $userId,
                $caseId,
                $request->input('route_confirmed') === '1',
                $request->input('warnings_acknowledged') === '1'
            );

            $_SESSION['dispatch_message'] = 'Versandauftrag wurde als '
                . $result['status']
                . ' in die Queue eingestellt.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['dispatch_error'] = $e->getMessage();
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/dispatch');
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

    private function pullFlash(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return is_string($value) ? $value : null;
    }
}
