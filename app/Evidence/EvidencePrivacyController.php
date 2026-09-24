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

final class EvidencePrivacyController
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

        $evidenceId = (string) $request->route('id', '');

        try {
            $detail = $this->service()->detail($userId, $evidenceId);
        } catch (\MeldeVerkehr\Auth\AuthorizationException $e) {
            return Response::html('<h1>403</h1><p>Kein Zugriff auf diesen Nachweis.</p>', 403);
        } catch (\DomainException $e) {
            return Response::html('<h1>404</h1><p>Nachweis nicht gefunden.</p>', 404);
        }

        return Response::html($this->view->render('evidence/privacy', [
            'detail' => $detail,
            'regionTypes' => EvidencePrivacyService::regionTypes(),
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('privacy_message'),
            'error' => $this->pullFlash('privacy_error'),
        ]));
    }

    public function addRegion(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $evidenceId = (string) $request->route('id', '');

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['privacy_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return Response::redirect('/evidence/' . rawurlencode($evidenceId) . '/privacy');
        }

        try {
            $this->service()->addRegion(
                $userId,
                $evidenceId,
                (string) $request->input('region_type', ''),
                $this->percent($request->input('x_pct')),
                $this->percent($request->input('y_pct')),
                $this->percent($request->input('width_pct')),
                $this->percent($request->input('height_pct'))
            );
            $_SESSION['privacy_message'] = 'Privacy-Bereich wurde hinzugefügt. Die Privacy-Prüfung muss erneut bestätigt werden.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['privacy_error'] = $e->getMessage();
        }

        return Response::redirect('/evidence/' . rawurlencode($evidenceId) . '/privacy');
    }

    public function dismissRegion(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $regionId = (string) $request->route('id', '');
        $evidenceId = trim((string) $request->input('evidence_id', ''));

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            return Response::redirect('/evidence/' . rawurlencode($evidenceId) . '/privacy');
        }

        try {
            $resolvedEvidenceId = $this->service()->dismissRegion($userId, $regionId);
            $_SESSION['privacy_message'] = 'Privacy-Bereich wurde entfernt. Die Privacy-Prüfung muss erneut bestätigt werden.';
            $evidenceId = $resolvedEvidenceId;
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['privacy_error'] = $e->getMessage();
        }

        return Response::redirect('/evidence/' . rawurlencode($evidenceId) . '/privacy');
    }

    public function confirm(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $evidenceId = (string) $request->route('id', '');

        if (
            !Csrf::validate((string) $request->input('_csrf', ''))
            || $request->input('privacy_confirm') !== '1'
        ) {
            $_SESSION['privacy_error'] = 'Bitte bestätige, dass du die Privacy-Prüfung durchgeführt hast.';
            return Response::redirect('/evidence/' . rawurlencode($evidenceId) . '/privacy');
        }

        try {
            $result = $this->service()->confirmReview($userId, $evidenceId);
            $_SESSION['privacy_message'] = sprintf(
                'Privacy-Prüfung bestätigt. Redigierte Kopie Version %d wurde erzeugt.',
                (int) $result['public_version_no']
            );
        } catch (Throwable $e) {
            if ($e instanceof \InvalidArgumentException || $e instanceof \DomainException || $e instanceof \MeldeVerkehr\Auth\AuthorizationException) {
                $_SESSION['privacy_error'] = $e->getMessage();
            } else {
                throw $e;
            }
        }

        return Response::redirect('/evidence/' . rawurlencode($evidenceId) . '/privacy');
    }

    public function preview(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        try {
            $preview = $this->service()->preview(
                $userId,
                (string) $request->route('id', ''),
                (string) $request->query('variant', 'WORKING')
            );

            return Response::binary(
                $preview['body'],
                $preview['mime_type'],
                200,
                ['Content-Disposition' => 'inline']
            );
        } catch (\MeldeVerkehr\Auth\AuthorizationException $e) {
            return Response::html('<h1>403</h1>', 403);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Response::html('<h1>404</h1><p>Vorschau nicht verfügbar.</p>', 404);
        }
    }

    private function percent(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('Privacy-Koordinaten sind ungültig.');
        }

        return ((float) $value) / 100;
    }

    private function service(): EvidencePrivacyService
    {
        $storage = new EvidenceStorage($this->app->basePath() . '/storage/app');

        return new EvidencePrivacyService(
            $this->app->database(),
            new AuthorizationService(new PermissionService($this->app->database())),
            $storage,
            new EvidenceImageProcessor($storage),
            new AuditLogger(
                $this->app->database(),
                (string) $this->app->config()->get('app.key', '')
            )
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
