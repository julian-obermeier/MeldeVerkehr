<?php

declare(strict_types=1);

namespace MeldeVerkehr\Witness;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Auth\AuthManager;
use MeldeVerkehr\Auth\AuthService;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\View;

final class FinalReviewController
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

        try {
            $summary = $this->service()->summary($userId, $caseId);
        } catch (\MeldeVerkehr\Auth\AuthorizationException $e) {
            return Response::html('<h1>403</h1><p>Kein Zugriff auf diesen Vorgang.</p>', 403);
        } catch (\DomainException $e) {
            return Response::html('<h1>404</h1><p>Vorgang nicht gefunden.</p>', 404);
        }

        return Response::html($this->view->render('witness/final-review', [
            'summary' => $summary,
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('final_review_message'),
            'error' => $this->pullFlash('final_review_error'),
        ]));
    }

    public function confirm(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = (string) $request->route('id', '');

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['final_review_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/final-review');
        }

        try {
            $result = $this->service()->confirm(
                $userId,
                $caseId,
                $request->input('acknowledge_yellow') === '1'
            );

            $_SESSION['case_message'] = 'Finaler Qualitätsreview Version '
                . (int) $result['version_no']
                . ' bestätigt. Der Vorgang ist jetzt versandbereit.';

            return Response::redirect('/cases/' . rawurlencode($caseId));
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['final_review_error'] = $e->getMessage();
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/final-review');
        }
    }

    private function service(): FinalReviewService
    {
        $pdo = $this->app->database();
        $permissions = new PermissionService($pdo);
        $authorization = new AuthorizationService($permissions);
        $key = (string) $this->app->config()->get('app.key', '');
        $audit = new AuditLogger($pdo, $key);

        $cases = new CaseService(
            $pdo,
            $authorization,
            new SecretCipher($key),
            $key,
            $audit,
            (string) $this->app->config()->get('app.timezone', 'Europe/Berlin')
        );

        $witness = new WitnessService(
            $pdo,
            $authorization,
            $cases,
            new SecretCipher($key),
            new NeutralNarrativeBuilder(
                (string) $this->app->config()->get('app.timezone', 'Europe/Berlin')
            ),
            $audit
        );

        return new FinalReviewService(
            $pdo,
            $authorization,
            $cases,
            $witness,
            $audit
        );
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
