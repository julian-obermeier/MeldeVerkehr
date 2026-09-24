<?php

declare(strict_types=1);

namespace MeldeVerkehr\Offline;

use MeldeVerkehr\Assist\ImageQualityAnalyzer;
use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthManager;
use MeldeVerkehr\Auth\AuthService;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Evidence\EvidenceImageProcessor;
use MeldeVerkehr\Evidence\EvidenceService;
use MeldeVerkehr\Evidence\EvidenceStorage;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Security\SecretCipher;

final class OfflineDraftController
{
    private readonly AuthManager $auth;

    public function __construct(private readonly Application $app)
    {
        $this->auth = new AuthManager();
    }

    public function csrf(Request $request): Response
    {
        $userId = $this->requireUserId();

        if ($userId === null) {
            return $this->error('AUTH_REQUIRED', 'Bitte zuerst online anmelden.', 401);
        }

        return $this->success([
            'csrf' => Csrf::token(),
            'user_id' => $userId,
        ]);
    }

    public function sync(Request $request): Response
    {
        $userId = $this->requireUserId();

        if ($userId === null) {
            return $this->error('AUTH_REQUIRED', 'Bitte zuerst online anmelden.', 401);
        }

        if (!Csrf::validate((string) $request->server('HTTP_X_CSRF_TOKEN', ''))) {
            return $this->error('CSRF_FAILED', 'Sitzungstoken ist ungültig.', 419);
        }

        try {
            $json = $request->json();
            $localId = trim((string) ($json['local_id'] ?? ''));
            $localVersion = max(1, (int) ($json['local_version'] ?? 1));
            $serverCaseId = trim((string) ($json['server_case_id'] ?? ''));
            $serverVersion = trim((string) ($json['server_version'] ?? ''));
            $resolution = strtoupper(trim((string) ($json['resolution'] ?? 'NONE')));
            $data = is_array($json['data'] ?? null) ? $json['data'] : [];

            if ($localId === '' || mb_strlen($localId) > 120) {
                throw new \InvalidArgumentException('Lokale Entwurfs-ID ist ungültig.');
            }

            if (!in_array($resolution, ['NONE','USE_LOCAL','USE_SERVER'], true)) {
                throw new \InvalidArgumentException('Ungültige Konfliktentscheidung.');
            }

            $service = $this->cases();
            $existing = null;

            if ($serverCaseId !== '') {
                $existing = $service->findOwned($userId, $serverCaseId);

                if ($existing === null) {
                    return $this->error('CASE_NOT_FOUND', 'Server-Entwurf wurde nicht gefunden.', 404);
                }

                $currentVersion = (string) $existing['case']['updated_at'];

                if ($serverVersion === '' || !hash_equals($currentVersion, $serverVersion)) {
                    if ($resolution === 'USE_SERVER') {
                        return $this->success([
                            'conflict_resolved' => 'SERVER',
                            'case_id' => $serverCaseId,
                            'public_number' => $existing['case']['public_number'],
                            'server_version' => $currentVersion,
                            'local_version' => $localVersion,
                            'case_url' => '/cases/' . rawurlencode($serverCaseId),
                        ]);
                    }

                    if ($resolution !== 'USE_LOCAL') {
                        return Response::json([
                            'success' => false,
                            'data' => [
                                'conflict' => true,
                                'case_id' => $serverCaseId,
                                'public_number' => $existing['case']['public_number'],
                                'server_version' => $currentVersion,
                                'local_version' => $localVersion,
                            ],
                            'errors' => [[
                                'code' => 'VERSION_CONFLICT',
                                'message' => 'Server- und Offline-Entwurf wurden beide geändert.',
                            ]],
                            'meta' => [],
                        ], 409);
                    }
                }
            } else {
                $existing = $service->createDraft($userId);
                $serverCaseId = (string) $existing['case']['id'];
            }

            $this->applyData($service, $userId, $serverCaseId, $data);

            $current = $service->findOwned($userId, $serverCaseId);
            if ($current === null) {
                throw new \RuntimeException('Synchronisierter Entwurf konnte nicht geladen werden.');
            }

            return $this->success([
                'conflict_resolved' => $resolution === 'USE_LOCAL' ? 'LOCAL' : null,
                'case_id' => $serverCaseId,
                'public_number' => $current['case']['public_number'],
                'server_version' => (string) $current['case']['updated_at'],
                'local_version' => $localVersion,
                'case_status' => (string) $current['case']['status'],
                'case_url' => '/cases/' . rawurlencode($serverCaseId),
                'evidence_url' => '/cases/' . rawurlencode($serverCaseId) . '/evidence',
            ]);
        } catch (\JsonException|\InvalidArgumentException|\DomainException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), 422);
        }
    }

    public function uploadEvidence(Request $request): Response
    {
        $userId = $this->requireUserId();

        if ($userId === null) {
            return $this->error('AUTH_REQUIRED', 'Bitte zuerst online anmelden.', 401);
        }

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            return $this->error('CSRF_FAILED', 'Sitzungstoken ist ungültig.', 419);
        }

        $caseId = (string) $request->route('id', '');
        $file = $request->file('evidence');

        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->error('UPLOAD_INVALID', 'Bitte eine gültige Bilddatei auswählen.', 422);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return $this->error('UPLOAD_INVALID', 'Upload konnte nicht verifiziert werden.', 422);
        }

        try {
            $item = $this->evidence()->storeFile(
                $userId,
                $caseId,
                $tmp,
                (string) ($file['name'] ?? 'offline-bild'),
                (string) $request->input('category', 'OVERVIEW'),
                'OFFLINE_SYNC'
            );

            return $this->success([
                'evidence_id' => $item['id'] ?? null,
                'case_id' => $caseId,
            ], 201);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->error('UPLOAD_REJECTED', $e->getMessage(), 422);
        }
    }

    private function applyData(
        CaseService $service,
        string $userId,
        string $caseId,
        array $data
    ): void {
        $vehicle = is_array($data['vehicle'] ?? null) ? $data['vehicle'] : [];
        $plate = trim((string) ($vehicle['license_plate'] ?? ''));
        $vehicleType = trim((string) ($vehicle['vehicle_type'] ?? ''));

        if ($plate !== '' || $vehicleType !== '') {
            if ($plate === '' || $vehicleType === '') {
                throw new \InvalidArgumentException(
                    'Für Offline-Fahrzeugdaten werden Kennzeichen und Fahrzeugart gemeinsam benötigt.'
                );
            }

            $service->saveVehicle($userId, $caseId, $vehicle);
        }

        $location = is_array($data['location'] ?? null) ? $data['location'] : [];
        $hasLocation = trim((string) ($location['street'] ?? '')) !== ''
            || trim((string) ($location['city'] ?? '')) !== ''
            || trim((string) ($location['latitude'] ?? '')) !== ''
            || trim((string) ($location['longitude'] ?? '')) !== '';

        if ($hasLocation) {
            $location['traffic_space_type'] = (string) ($location['traffic_space_type'] ?? 'UNKNOWN');
            $location['access_type'] = (string) ($location['access_type'] ?? 'UNCLEAR');
            $location['country'] = (string) ($location['country'] ?? 'DE');
            $service->saveLocation($userId, $caseId, $location);
        }

        $observation = is_array($data['observation'] ?? null) ? $data['observation'] : [];
        if (trim((string) ($observation['observed_from'] ?? '')) !== '') {
            $service->saveObservation($userId, $caseId, $observation);
        }
    }

    private function requireUserId(): ?string
    {
        $userId = $this->auth->id();

        if ($userId === null) {
            return null;
        }

        $user = (new AuthService($this->app->database()))->findById($userId);

        if (
            $user === null
            || $user['status'] !== 'ACTIVE'
            || $user['email_verified_at'] === null
        ) {
            return null;
        }

        return $userId;
    }

    private function cases(): CaseService
    {
        $key = (string) $this->app->config()->get('app.key', '');
        $permissions = new PermissionService($this->app->database());

        return new CaseService(
            $this->app->database(),
            new AuthorizationService($permissions),
            new SecretCipher($key),
            $key,
            new AuditLogger($this->app->database(), $key),
            (string) $this->app->config()->get('app.timezone', 'Europe/Berlin')
        );
    }

    private function evidence(): EvidenceService
    {
        $key = (string) $this->app->config()->get('app.key', '');
        $permissions = new PermissionService($this->app->database());
        $storage = new EvidenceStorage($this->app->basePath() . '/storage/app');

        return new EvidenceService(
            $this->app->database(),
            new AuthorizationService($permissions),
            $storage,
            new AuditLogger($this->app->database(), $key),
            new EvidenceImageProcessor($storage),
            new ImageQualityAnalyzer()
        );
    }

    private function success(mixed $data, int $status = 200): Response
    {
        return Response::json([
            'success' => true,
            'data' => $data,
            'errors' => [],
            'meta' => [],
        ], $status);
    }

    private function error(string $code, string $message, int $status): Response
    {
        return Response::json([
            'success' => false,
            'data' => null,
            'errors' => [['code' => $code, 'message' => $message]],
            'meta' => [],
        ], $status);
    }
}
