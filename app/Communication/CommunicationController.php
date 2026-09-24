<?php

declare(strict_types=1);

namespace MeldeVerkehr\Communication;

use MeldeVerkehr\Auth\AuthManager;
use MeldeVerkehr\Auth\AuthService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Support\View;

final class CommunicationController
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
        $services = CommunicationServiceFactory::services($this->app);

        try {
            $data = $services['communications']->listForCase($userId, $caseId);

            foreach ($data['messages'] as &$message) {
                $message['reply_draft'] = $message['direction'] === 'INBOUND'
                    ? $services['replies']->latestForMessage($userId, (string) $message['id'])
                    : null;
            }
            unset($message);
        } catch (\MeldeVerkehr\Auth\AuthorizationException $e) {
            return Response::html('<h1>403</h1><p>Kein Zugriff auf diesen Vorgang.</p>', 403);
        } catch (\DomainException $e) {
            return Response::html('<h1>404</h1><p>Vorgang nicht gefunden.</p>', 404);
        }

        return Response::html($this->view->render('communication/index', [
            'data' => $data,
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('communication_message'),
            'error' => $this->pullFlash('communication_error'),
            'inboundEnabled' => (bool) $this->app->config()->get('communications.inbound_enabled', false),
            'transportMode' => (string) $this->app->config()->get('dispatch.transport', 'dry_run'),
        ]));
    }

    public function completeTask(Request $request): Response
    {
        return $this->caseMutation($request, function (array $services, string $userId): string {
            return $services['communications']->completeTask(
                $userId,
                (string) $request->route('id', '')
            );
        }, 'Aufgabe wurde als erledigt markiert.');
    }

    public function resolveDeadline(Request $request): Response
    {
        return $this->caseMutation($request, function (array $services, string $userId): string {
            return $services['communications']->resolveDeadline(
                $userId,
                (string) $request->route('id', '')
            );
        }, 'Frist wurde als erledigt markiert.');
    }

    public function createDraft(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = trim((string) $request->input('case_id', ''));

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['communication_error'] = 'Sitzung abgelaufen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/communication');
        }

        try {
            $draft = CommunicationServiceFactory::services($this->app)['replies']->createDraft(
                $userId,
                (string) $request->route('id', '')
            );
            $_SESSION['communication_message'] = 'Antwortentwurf Version ' . (int) $draft['version_no'] . ' wurde erstellt.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['communication_error'] = $e->getMessage();
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/communication');
    }

    public function saveDraft(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = trim((string) $request->input('case_id', ''));

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['communication_error'] = 'Sitzung abgelaufen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/communication');
        }

        try {
            $draft = CommunicationServiceFactory::services($this->app)['replies']->saveDraft(
                $userId,
                (string) $request->route('id', ''),
                (string) $request->input('body', '')
            );
            $_SESSION['communication_message'] = 'Antwortentwurf als Version ' . (int) $draft['version_no'] . ' gespeichert.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['communication_error'] = $e->getMessage();
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/communication');
    }

    public function queueDraft(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = trim((string) $request->input('case_id', ''));

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['communication_error'] = 'Sitzung abgelaufen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/communication');
        }

        try {
            $result = CommunicationServiceFactory::services($this->app)['replies']->queueSend(
                $userId,
                (string) $request->route('id', ''),
                $request->input('confirm_send') === '1'
            );
            $_SESSION['communication_message'] = 'Antwort wurde als ' . $result['status'] . ' in die Queue eingestellt.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['communication_error'] = $e->getMessage();
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/communication');
    }

    private function caseMutation(Request $request, callable $operation, string $success): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = trim((string) $request->input('case_id', ''));

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['communication_error'] = 'Sitzung abgelaufen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/communication');
        }

        try {
            $resolvedCaseId = $operation(CommunicationServiceFactory::services($this->app), $userId);
            $_SESSION['communication_message'] = $success;
            $caseId = $resolvedCaseId;
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['communication_error'] = $e->getMessage();
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/communication');
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
