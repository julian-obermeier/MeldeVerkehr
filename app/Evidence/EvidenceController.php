<?php

declare(strict_types=1);

namespace MeldeVerkehr\Evidence;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Auth\AuthManager;
use MeldeVerkehr\Auth\AuthService;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Support\View;
use Throwable;

final class EvidenceController
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
            $items = $this->service()->listForCase($userId, $caseId);
        } catch (\DomainException $e) {
            return Response::html('<h1>404</h1><p>Vorgang nicht gefunden.</p>', 404);
        } catch (\MeldeVerkehr\Auth\AuthorizationException $e) {
            return Response::html('<h1>403</h1><p>Kein Zugriff auf diesen Vorgang.</p>', 403);
        }

        $caseStmt = $this->app->database()->prepare(
            'SELECT public_number, status FROM cases WHERE id = :id LIMIT 1'
        );
        $caseStmt->execute(['id' => $caseId]);
        $case = $caseStmt->fetch();

        return Response::html($this->view->render('evidence/index', [
            'caseId' => $caseId,
            'case' => is_array($case) ? $case : [],
            'items' => $items,
            'categories' => EvidenceCategory::all(),
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('evidence_message'),
            'error' => $this->pullFlash('evidence_error'),
        ]));
    }

    public function upload(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = (string) $request->route('id', '');

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['evidence_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/evidence');
        }

        $file = $request->file('evidence');

        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $_SESSION['evidence_error'] = 'Bitte eine Bilddatei auswählen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/evidence');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $_SESSION['evidence_error'] = 'Upload konnte nicht verifiziert werden.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/evidence');
        }

        try {
            $this->service()->storeFile(
                $userId,
                $caseId,
                $tmp,
                (string) ($file['name'] ?? 'bild'),
                (string) $request->input('category', ''),
                'UPLOAD'
            );
            $_SESSION['evidence_message'] = 'Bildnachweis wurde als geschütztes Original gespeichert.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['evidence_error'] = $e->getMessage();
        } catch (Throwable $e) {
            throw $e;
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/evidence');
    }

    public function remove(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            return Response::redirect('/dashboard');
        }

        $evidenceId = (string) $request->route('id', '');
        $caseId = trim((string) $request->input('case_id', ''));

        try {
            $this->service()->markRemoved($userId, $evidenceId);
            $_SESSION['evidence_message'] = 'Nachweis wurde aus dem aktiven Beweissatz entfernt. Das Original bleibt revisionssicher erhalten.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['evidence_error'] = $e->getMessage();
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/evidence');
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

    private function service(): EvidenceService
    {
        $permissions = new PermissionService($this->app->database());

        $storage = new EvidenceStorage($this->app->basePath() . '/storage/app');

        return new EvidenceService(
            $this->app->database(),
            new AuthorizationService($permissions),
            $storage,
            new AuditLogger(
                $this->app->database(),
                (string) $this->app->config()->get('app.key', '')
            ),
            new EvidenceImageProcessor($storage)
        );
    }

    private function pullFlash(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return is_string($value) ? $value : null;
    }
}
