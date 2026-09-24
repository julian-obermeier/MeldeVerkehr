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
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\View;
use Throwable;

final class CaseController
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

        $status = trim((string) $request->query('status', ''));
        $cases = $this->service()->listOwned($userId, $status !== '' ? $status : null);

        return Response::html($this->view->render('cases/index', [
            'cases' => $cases,
            'status' => $status,
            'statuses' => CaseStatus::all(),
            'csrf' => Csrf::token(),
        ]));
    }

    public function create(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            return Response::redirect('/cases');
        }

        $case = $this->service()->createDraft($userId);

        return Response::redirect('/cases/' . rawurlencode((string) $case['case']['id']));
    }

    public function show(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        try {
            $case = $this->service()->findOwned($userId, (string) $request->route('id', ''));
        } catch (AuthorizationException $e) {
            return Response::html('<h1>403</h1><p>Kein Zugriff auf diesen Vorgang.</p>', 403);
        }

        if ($case === null) {
            return Response::html('<h1>404</h1><p>Vorgang nicht gefunden.</p>', 404);
        }

        return Response::html($this->view->render('cases/show', [
            'data' => $case,
            'offenses' => $this->service()->availableOffenses(),
            'csrf' => Csrf::token(),
        ]));
    }

    public function saveVehicle(Request $request): Response
    {
        return $this->mutate($request, fn (string $userId, string $caseId) =>
            $this->service()->saveVehicle($userId, $caseId, [
                'license_plate' => $request->input('license_plate'),
                'vehicle_type' => $request->input('vehicle_type'),
                'color' => $request->input('color'),
                'make' => $request->input('make'),
                'model' => $request->input('model'),
            ])
        );
    }

    public function saveLocation(Request $request): Response
    {
        return $this->mutate($request, fn (string $userId, string $caseId) =>
            $this->service()->saveLocation($userId, $caseId, [
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'street' => $request->input('street'),
                'house_number' => $request->input('house_number'),
                'postal_code' => $request->input('postal_code'),
                'city' => $request->input('city'),
                'district' => $request->input('district'),
                'state' => $request->input('state'),
                'country' => $request->input('country', 'DE'),
                'direction' => $request->input('direction'),
                'road_side' => $request->input('road_side'),
                'location_description' => $request->input('location_description'),
                'traffic_space_type' => $request->input('traffic_space_type', 'UNKNOWN'),
                'access_type' => $request->input('access_type', 'UNCLEAR'),
            ])
        );
    }

    public function saveOffense(Request $request): Response
    {
        return $this->mutate($request, fn (string $userId, string $caseId) =>
            $this->service()->setPrimaryOffense(
                $userId,
                $caseId,
                trim((string) $request->input('offense_version_id', ''))
            )
        );
    }

    private function mutate(Request $request, callable $operation): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = (string) $request->route('id', '');

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['case_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return Response::redirect('/cases/' . rawurlencode($caseId));
        }

        try {
            $operation($userId, $caseId);
            $_SESSION['case_message'] = 'Änderungen wurden gespeichert.';
        } catch (Throwable $e) {
            $_SESSION['case_error'] = $e->getMessage();
        }

        return Response::redirect('/cases/' . rawurlencode($caseId));
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

    private function service(): CaseService
    {
        $permissions = new PermissionService($this->app->database());

        return new CaseService(
            $this->app->database(),
            new AuthorizationService($permissions),
            new SecretCipher((string) $this->app->config()->get('app.key', '')),
            (string) $this->app->config()->get('app.key', ''),
            new AuditLogger(
                $this->app->database(),
                (string) $this->app->config()->get('app.key', '')
            )
        );
    }
}
