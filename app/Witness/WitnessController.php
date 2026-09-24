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

final class WitnessController
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
            $detail = $this->service()->detail($userId, $caseId);
        } catch (\MeldeVerkehr\Auth\AuthorizationException $e) {
            return Response::html('<h1>403</h1><p>Kein Zugriff auf diesen Vorgang.</p>', 403);
        } catch (\DomainException $e) {
            return Response::html('<h1>409</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>', 409);
        }

        return Response::html($this->view->render('witness/index', [
            'detail' => $detail,
            'declarationText' => WitnessService::DECLARATION_TEXT,
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('witness_message'),
            'error' => $this->pullFlash('witness_error'),
        ]));
    }

    public function saveObservation(Request $request): Response
    {
        return $this->mutateCase($request, function (WitnessService $service, string $userId, string $caseId) use ($request): void {
            $result = $service->saveObservation($userId, $caseId, [
                'observation_text' => $request->input('observation_text'),
                'impact_text' => $request->input('impact_text'),
                'context_text' => $request->input('context_text'),
            ]);

            $_SESSION['witness_message'] = 'Eigene Beobachtung als Version ' . (int) $result['version_no'] . ' gespeichert.';
        });
    }

    public function generateNarrative(Request $request): Response
    {
        return $this->mutateCase($request, function (WitnessService $service, string $userId, string $caseId): void {
            $result = $service->generateNarrative($userId, $caseId);
            $_SESSION['witness_message'] = 'Neutraler Sachverhalt als Version ' . (int) $result['version_no'] . ' erzeugt.';
        });
    }

    public function saveNarrative(Request $request): Response
    {
        return $this->mutateCase($request, function (WitnessService $service, string $userId, string $caseId) use ($request): void {
            $result = $service->saveNarrative(
                $userId,
                $caseId,
                (string) $request->input('narrative_text', '')
            );

            $_SESSION['witness_message'] = 'Bearbeiteter Sachverhalt als Version ' . (int) $result['version_no'] . ' gespeichert.';
        });
    }

    public function createReport(Request $request): Response
    {
        return $this->mutateCase($request, function (WitnessService $service, string $userId, string $caseId): void {
            $result = $service->createReport($userId, $caseId);
            $_SESSION['witness_message'] = 'Zeugenbericht Version ' . (int) $result['version_no'] . ' wurde als unveränderlicher Snapshot erstellt.';
        });
    }

    public function confirmReport(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $reportId = (string) $request->route('id', '');
        $caseId = trim((string) $request->input('case_id', ''));

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['witness_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/witness');
        }

        try {
            $result = $this->service()->confirmReport(
                $userId,
                $reportId,
                $request->input('declaration_accepted') === '1',
                $this->auditMetadata($request)
            );
            $_SESSION['witness_message'] = 'Zeugenbericht Version ' . (int) $result['version_no'] . ' wurde elektronisch bestätigt.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['witness_error'] = $e->getMessage();
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/witness');
    }

    private function mutateCase(Request $request, callable $operation): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = (string) $request->route('id', '');

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['witness_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/witness');
        }

        try {
            $operation($this->service(), $userId, $caseId);
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['witness_error'] = $e->getMessage();
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/witness');
    }

    private function service(): WitnessService
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

        return new WitnessService(
            $pdo,
            $authorization,
            $cases,
            new SecretCipher($key),
            new NeutralNarrativeBuilder(
                (string) $this->app->config()->get('app.timezone', 'Europe/Berlin')
            ),
            $audit
        );
    }

    private function auditMetadata(Request $request): array
    {
        $key = (string) $this->app->config()->get('app.key', '');
        $ip = trim((string) $request->server('REMOTE_ADDR', ''));
        $userAgent = trim((string) $request->server('HTTP_USER_AGENT', ''));

        return [
            'ip_hash' => $ip === '' ? null : hash_hmac('sha256', $ip, $key),
            'user_agent_hash' => $userAgent === '' ? null : hash_hmac('sha256', $userAgent, $key),
            'session_id_hash' => session_id() === '' ? null : hash_hmac('sha256', session_id(), $key),
        ];
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
