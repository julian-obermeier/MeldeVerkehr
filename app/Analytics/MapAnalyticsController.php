<?php

declare(strict_types=1);

namespace MeldeVerkehr\Analytics;

use MeldeVerkehr\Auth\AuthManager;
use MeldeVerkehr\Auth\AuthService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Support\View;

final class MapAnalyticsController
{
    private readonly AuthManager $auth;
    private readonly View $view;

    public function __construct(private readonly Application $app)
    {
        $this->auth = new AuthManager();
        $this->view = new View($app->basePath() . '/resources/views');
    }

    public function map(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $service = MapAnalyticsServiceFactory::make($this->app);

        return Response::html($this->view->render('analytics/map', [
            'points' => $service->mapData($userId, $this->nullable($request->query('status'))),
            'hotspots' => $service->hotspotCandidates($userId),
        ]));
    }

    public function analytics(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        try {
            $from = $this->date($request->query('from'));
            $to = $this->date($request->query('to'));
        } catch (\InvalidArgumentException $e) {
            return Response::html('<h1>400</h1><p>Ungültiger Zeitraum.</p>', 400);
        }

        return Response::html($this->view->render('analytics/index', [
            'analytics' => MapAnalyticsServiceFactory::make($this->app)->analytics($userId, $from, $to),
        ]));
    }

    public function areas(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $service = MapAnalyticsServiceFactory::make($this->app);

        return Response::html($this->view->render('analytics/areas', [
            'areas' => $service->problemAreas($userId),
            'hotspots' => $service->hotspotCandidates($userId),
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('analytics_message'),
            'error' => $this->pullFlash('analytics_error'),
        ]));
    }

    public function createArea(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['analytics_error'] = 'Sitzung abgelaufen.';
            return Response::redirect('/problem-areas');
        }

        try {
            $area = MapAnalyticsServiceFactory::make($this->app)->createProblemArea(
                $userId,
                (string) $request->input('name', ''),
                (float) $request->input('latitude', 0),
                (float) $request->input('longitude', 0),
                (int) $request->input('radius_m', 150),
                (string) $request->input('source', 'MANUAL'),
                $this->nullable($request->input('city')),
                $this->nullable($request->input('street'))
            );
            $_SESSION['analytics_message'] = 'Problemstelle wurde angelegt und mit eigenen Vorgängen abgeglichen.';
            return Response::redirect('/problem-areas/' . rawurlencode((string) $area['id']));
        } catch (\InvalidArgumentException|\DomainException $e) {
            $_SESSION['analytics_error'] = $e->getMessage();
            return Response::redirect('/problem-areas');
        }
    }

    public function area(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        try {
            $area = MapAnalyticsServiceFactory::make($this->app)->problemArea(
                $userId,
                (string) $request->route('id', '')
            );
        } catch (\DomainException $e) {
            return Response::html('<h1>404</h1><p>Problemstelle nicht gefunden.</p>', 404);
        }

        return Response::html($this->view->render('analytics/area', [
            'area' => $area,
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('analytics_message'),
            'error' => $this->pullFlash('analytics_error'),
        ]));
    }

    public function syncArea(Request $request): Response
    {
        return $this->areaMutation($request, function (MapAnalyticsService $service, string $userId, string $areaId): void {
            $result = $service->syncProblemArea($userId, $areaId);
            $_SESSION['analytics_message'] = (int) $result['linked_count'] . ' eigene Vorgänge wurden zugeordnet.';
        });
    }

    public function createReport(Request $request): Response
    {
        return $this->areaMutation($request, function (MapAnalyticsService $service, string $userId, string $areaId) use ($request): void {
            $from = $this->date($request->input('from')) ?? new \DateTimeImmutable('first day of January');
            $to = $this->date($request->input('to')) ?? new \DateTimeImmutable('today');

            $report = $service->createMunicipalReport($userId, $areaId, $from, $to);
            $_SESSION['analytics_message'] = 'Anonymisierter Problembericht Version ' . (int) $report['version_no'] . ' erstellt.';
            $_SESSION['analytics_report_id'] = $report['id'];
        }, true);
    }

    public function report(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        try {
            $report = MapAnalyticsServiceFactory::make($this->app)->municipalReport(
                $userId,
                (string) $request->route('id', '')
            );
        } catch (\DomainException $e) {
            return Response::html('<h1>404</h1><p>Problembericht nicht gefunden.</p>', 404);
        }

        return Response::html($this->view->render('analytics/report', [
            'report' => $report,
        ]));
    }

    private function areaMutation(Request $request, callable $operation, bool $redirectReport = false): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $areaId = (string) $request->route('id', '');

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['analytics_error'] = 'Sitzung abgelaufen.';
            return Response::redirect('/problem-areas/' . rawurlencode($areaId));
        }

        try {
            $operation(MapAnalyticsServiceFactory::make($this->app), $userId, $areaId);

            if ($redirectReport) {
                $reportId = $_SESSION['analytics_report_id'] ?? null;
                unset($_SESSION['analytics_report_id']);

                if (is_string($reportId) && $reportId !== '') {
                    return Response::redirect('/municipal-reports/' . rawurlencode($reportId));
                }
            }
        } catch (\InvalidArgumentException|\DomainException $e) {
            $_SESSION['analytics_error'] = $e->getMessage();
        }

        return Response::redirect('/problem-areas/' . rawurlencode($areaId));
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Ungültiges Datum.');
        }

        return $date;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
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
