<?php

declare(strict_types=1);

namespace MeldeVerkehr\Cases;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationException;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Auth\AuthManager;
use MeldeVerkehr\Auth\AuthService;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Support\View;

final class CaseLifecycleController
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
            $data = $this->service()->overview($userId, $caseId);
        } catch (AuthorizationException) {
            return Response::html('<h1>403</h1><p>Kein Zugriff auf diesen Vorgang.</p>', 403);
        } catch (\DomainException) {
            return Response::html('<h1>404</h1><p>Vorgang nicht gefunden.</p>', 404);
        }

        return Response::html($this->view->render('cases/lifecycle', [
            'data' => $data,
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('lifecycle_message'),
            'error' => $this->pullFlash('lifecycle_error'),
        ]));
    }

    public function exportClosure(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = (string) $request->route('id', '');
        $closureId = (string) $request->route('closure', '');

        try {
            $dossier = $this->service()->closureDossier($userId, $caseId, $closureId);
        } catch (AuthorizationException) {
            return Response::html('<h1>403</h1><p>Kein Zugriff auf diese Abschlussakte.</p>', 403);
        } catch (\DomainException $e) {
            return Response::html('<h1>404</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>', 404);
        }

        return Response::binary(
            (string) $dossier['json'],
            'application/json; charset=UTF-8',
            200,
            [
                'Content-Disposition' => 'attachment; filename="' . (string) $dossier['filename'] . '"',
                'X-Content-SHA256' => (string) $dossier['closure']['dossier_sha256'],
            ]
        );
    }

    public function addAmendment(Request $request): Response
    {
        return $this->mutate($request, function (string $userId, string $caseId) use ($request): string {
            $result = $this->service()->addAmendment(
                $userId,
                $caseId,
                (string) $request->input('title', ''),
                (string) $request->input('content', '')
            );

            return sprintf(
                'Nachtrag #%d wurde revisionssicher gespeichert (Vorgangsversion %d).',
                (int) $result['amendment_no'],
                (int) $result['version']['version_no']
            );
        });
    }

    public function requestCorrection(Request $request): Response
    {
        return $this->mutate($request, function (string $userId, string $caseId) use ($request): string {
            $result = $this->service()->requestCorrection(
                $userId,
                $caseId,
                (string) $request->input('category', 'OTHER'),
                (string) $request->input('original_value', ''),
                (string) $request->input('corrected_value', ''),
                (string) $request->input('reason', '')
            );

            return sprintf(
                'Korrekturantrag wurde angelegt. Der Ausgangsstand ist als Version %d eingefroren.',
                (int) $result['before_version']['version_no']
            );
        });
    }

    public function completeCorrection(Request $request): Response
    {
        return $this->mutate($request, function (string $userId, string $caseId) use ($request): string {
            $version = $this->service()->completeCorrection(
                $userId,
                $caseId,
                (string) $request->route('correction', ''),
                (string) $request->input('completion_note', '')
            );

            return sprintf(
                'Korrekturworkflow abgeschlossen und als Version %d dokumentiert.',
                (int) $version['version_no']
            );
        });
    }

    public function requestWithdrawal(Request $request): Response
    {
        return $this->mutate($request, function (string $userId, string $caseId) use ($request): string {
            $result = $this->service()->requestWithdrawal(
                $userId,
                $caseId,
                (string) $request->input('reason', '')
            );

            return sprintf(
                'Rücknahme wurde gestartet. Der vorherige Aktenstand ist Version %d.',
                (int) $result['before_version']['version_no']
            );
        });
    }

    public function completeWithdrawal(Request $request): Response
    {
        return $this->mutate($request, function (string $userId, string $caseId) use ($request): string {
            $closure = $this->service()->completeWithdrawal(
                $userId,
                $caseId,
                (string) $request->route('withdrawal', ''),
                (string) $request->input('completion_note', '')
            );

            return sprintf(
                'Rücknahme abgeschlossen. Abschlussakte #%d wurde erzeugt.',
                (int) $closure['closure_no']
            );
        });
    }

    public function close(Request $request): Response
    {
        return $this->mutate($request, function (string $userId, string $caseId) use ($request): string {
            $closure = $this->service()->closeCase(
                $userId,
                $caseId,
                (string) $request->input('closure_reason', 'OTHER'),
                (string) $request->input('closure_note', '')
            );

            return sprintf(
                'Vorgang abgeschlossen. Abschlussakte #%d wurde mit Integritätsnachweis erzeugt.',
                (int) $closure['closure_no']
            );
        });
    }

    public function archive(Request $request): Response
    {
        return $this->mutate($request, function (string $userId, string $caseId): string {
            $this->service()->archiveCase($userId, $caseId);

            return 'Vorgang wurde archiviert und der Archivstand versioniert.';
        });
    }

    private function mutate(Request $request, callable $operation): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = (string) $request->route('id', '');
        $redirect = '/cases/' . rawurlencode($caseId) . '/lifecycle';

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['lifecycle_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return Response::redirect($redirect);
        }

        try {
            $_SESSION['lifecycle_message'] = $operation($userId, $caseId);
        } catch (\InvalidArgumentException|\DomainException|AuthorizationException $e) {
            $_SESSION['lifecycle_error'] = $e->getMessage();
        }

        return Response::redirect($redirect);
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

    private function service(): CaseLifecycleService
    {
        $permissions = new PermissionService($this->app->database());

        return new CaseLifecycleService(
            $this->app->database(),
            new AuthorizationService($permissions),
            new AuditLogger(
                $this->app->database(),
                (string) $this->app->config()->get('app.key', '')
            )
        );
    }
}
