<?php

declare(strict_types=1);

namespace MeldeVerkehr\Assist;

use MeldeVerkehr\Auth\AuthManager;
use MeldeVerkehr\Auth\AuthService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Support\View;
use Throwable;

final class AssistController
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
            $overview = AssistServiceFactory::make($this->app)->overview($userId, $caseId);
        } catch (\MeldeVerkehr\Auth\AuthorizationException $e) {
            return Response::html('<h1>403</h1><p>Kein Zugriff auf diesen Vorgang.</p>', 403);
        } catch (\DomainException $e) {
            return Response::html('<h1>404</h1><p>Vorgang nicht gefunden.</p>', 404);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return Response::html(
                '<h1>Assistenz nicht verfügbar</h1><p>'
                . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</p>',
                503
            );
        }

        return Response::html($this->view->render('assist/index', [
            'overview' => $overview,
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('assist_message'),
            'error' => $this->pullFlash('assist_error'),
        ]));
    }

    public function quality(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = trim((string) $request->input('case_id', ''));
        $evidenceId = (string) $request->route('id', '');

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['assist_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/assist');
        }

        try {
            $result = AssistServiceFactory::make($this->app)->analyzeQuality(
                $userId,
                $evidenceId
            );

            $_SESSION['assist_message'] = 'Lokale Qualitätsanalyse Version '
                . (int) $result['version_no']
                . ' abgeschlossen: '
                . (string) $result['metrics']['overall_state'] . '.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['assist_error'] = $e->getMessage();
        } catch (Throwable $e) {
            $_SESSION['assist_error'] = 'Lokale Qualitätsanalyse ist technisch fehlgeschlagen.';
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/assist');
    }

    public function analyze(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = trim((string) $request->input('case_id', ''));
        $evidenceId = (string) $request->route('id', '');

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['assist_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/assist');
        }

        try {
            $result = AssistServiceFactory::make($this->app)->analyzeEvidence(
                $userId,
                $evidenceId,
                (string) $request->input('purpose', '')
            );

            $_SESSION['assist_message'] = sprintf(
                'Assistenzanalyse abgeschlossen: %d Vorschläge.',
                (int) $result['suggestion_count']
            );
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['assist_error'] = $e->getMessage();
        } catch (Throwable $e) {
            $_SESSION['assist_error'] = 'Assistenzanalyse ist technisch fehlgeschlagen.';
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/assist');
    }

    public function confirm(Request $request): Response
    {
        return $this->decide($request, true);
    }

    public function reject(Request $request): Response
    {
        return $this->decide($request, false);
    }

    public function applyPlate(Request $request): Response
    {
        return $this->apply($request, 'plate');
    }

    public function applyOffense(Request $request): Response
    {
        return $this->apply($request, 'offense');
    }

    private function decide(Request $request, bool $confirm): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = trim((string) $request->input('case_id', ''));

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['assist_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/assist');
        }

        try {
            $caseId = AssistServiceFactory::make($this->app)->decideSuggestion(
                $userId,
                (string) $request->route('id', ''),
                $confirm
            );
            $_SESSION['assist_message'] = $confirm
                ? 'Assistenzvorschlag wurde bestätigt. Vorgangsdaten wurden dadurch noch nicht verändert.'
                : 'Assistenzvorschlag wurde verworfen.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['assist_error'] = $e->getMessage();
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/assist');
    }

    private function apply(Request $request, string $type): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = trim((string) $request->input('case_id', ''));

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['assist_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/assist');
        }

        try {
            $service = AssistServiceFactory::make($this->app);
            $caseId = $type === 'plate'
                ? $service->applyPlateSuggestion($userId, (string) $request->route('id', ''))
                : $service->applyOffenseSuggestion($userId, (string) $request->route('id', ''));

            $_SESSION['case_message'] = $type === 'plate'
                ? 'Bestätigter Kennzeichenvorschlag wurde übernommen. Die Grunddaten müssen erneut geprüft werden.'
                : 'Bestätigter Tatbestandsvorschlag wurde übernommen. Die Grunddaten müssen erneut geprüft werden.';

            return Response::redirect('/cases/' . rawurlencode($caseId));
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['assist_error'] = $e->getMessage();
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/assist');
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
