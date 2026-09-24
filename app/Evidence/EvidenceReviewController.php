<?php

declare(strict_types=1);

namespace MeldeVerkehr\Evidence;

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

final class EvidenceReviewController
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

        return Response::html($this->view->render('evidence/review', [
            'summary' => $summary,
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('evidence_review_message'),
            'error' => $this->pullFlash('evidence_review_error'),
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
            $_SESSION['evidence_review_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/evidence/review');
        }

        try {
            $result = $this->service()->confirm(
                $userId,
                $caseId,
                $request->input('acknowledge_warnings') === '1'
            );
            $_SESSION['case_message'] = sprintf(
                'Beweismappe Version %d wurde eingefroren. Manifest: %s',
                (int) $result['package_version'],
                substr((string) $result['manifest_sha256'], 0, 16) . '…'
            );

            return Response::redirect('/cases/' . rawurlencode($caseId));
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['evidence_review_error'] = $e->getMessage();
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/evidence/review');
        }
    }

    private function service(): EvidenceReviewService
    {
        $pdo = $this->app->database();
        $permissions = new PermissionService($pdo);
        $authorization = new AuthorizationService($permissions);
        $audit = new AuditLogger(
            $pdo,
            (string) $this->app->config()->get('app.key', '')
        );

        $cases = new CaseService(
            $pdo,
            $authorization,
            new SecretCipher((string) $this->app->config()->get('app.key', '')),
            (string) $this->app->config()->get('app.key', ''),
            $audit,
            (string) $this->app->config()->get('app.timezone', 'Europe/Berlin')
        );

        return new EvidenceReviewService(
            $pdo,
            $authorization,
            new EvidenceStorage($this->app->basePath() . '/storage/app'),
            $audit,
            $cases
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
