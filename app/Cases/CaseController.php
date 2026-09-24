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
        $search = trim((string) $request->query('q', ''));
        $cases = $this->service()->listOwned(
            $userId,
            $status !== '' ? $status : null,
            $search !== '' ? $search : null
        );

        return Response::html($this->view->render('cases/index', [
            'cases' => $cases,
            'status' => $status,
            'search' => $search,
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
            'caseData' => $case,
            'offenses' => $this->service()->availableOffenses(),
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('case_message'),
            'error' => $this->pullFlash('case_error'),
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

    public function saveObservation(Request $request): Response
    {
        return $this->mutate($request, fn (string $userId, string $caseId) =>
            $this->service()->saveObservation($userId, $caseId, [
                'observed_from' => $request->input('observed_from'),
                'observed_until' => $request->input('observed_until'),
                'obstruction' => $request->input('obstruction'),
                'endangerment' => $request->input('endangerment'),
                'damage' => $request->input('damage'),
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

    public function review(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = (string) $request->route('id', '');

        try {
            $summary = $this->service()->reviewSummary($userId, $caseId);

            if (($summary['data']['evidence_package'] ?? null) !== null) {
                return Response::redirect('/cases/' . rawurlencode($caseId) . '/evidence/review');
            }
        } catch (AuthorizationException $e) {
            return Response::html('<h1>403</h1><p>Kein Zugriff auf diesen Vorgang.</p>', 403);
        } catch (\DomainException $e) {
            return Response::html('<h1>404</h1><p>Vorgang nicht gefunden.</p>', 404);
        }

        return Response::html($this->view->render('cases/review', [
            'summary' => $summary,
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('case_message'),
            'error' => $this->pullFlash('case_error'),
        ]));
    }

    public function confirmReview(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = (string) $request->route('id', '');

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['case_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/review');
        }

        try {
            $this->service()->confirmCoreReview(
                $userId,
                $caseId,
                $request->input('acknowledge_warnings') === '1'
            );
            $_SESSION['case_message'] = 'Grunddaten wurden bestätigt. Der Vorgang ist bereit für die Beweiserfassung.';
            return Response::redirect('/cases/' . rawurlencode($caseId));
        } catch (\InvalidArgumentException|\DomainException|AuthorizationException $e) {
            $_SESSION['case_error'] = $e->getMessage();
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/review');
        }
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
        } catch (\InvalidArgumentException|\DomainException|AuthorizationException $e) {
            $_SESSION['case_error'] = $e->getMessage();
        } catch (Throwable $e) {
            throw $e;
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

    private function pullFlash(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return is_string($value) ? $value : null;
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
            ),
            (string) $this->app->config()->get('app.timezone', 'Europe/Berlin')
        );
    }
}
