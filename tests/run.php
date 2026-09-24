<?php

declare(strict_types=1);

use MeldeVerkehr\Assist\AssistService;
use MeldeVerkehr\Assist\ImageQualityAnalyzer;
use MeldeVerkehr\Assist\VisionProviderInterface;
use MeldeVerkehr\AuthorityPortal\AuthorityAccessService;
use MeldeVerkehr\AuthorityPortal\AuthorityApiTokenService;
use MeldeVerkehr\AuthorityPortal\AuthorityExportService;
use MeldeVerkehr\AuthorityPortal\AuthorityHolderService;
use MeldeVerkehr\AuthorityPortal\AuthorityPortalService;
use MeldeVerkehr\Analytics\MapAnalyticsService;
use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationException;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Auth\AuthService;
use MeldeVerkehr\Auth\LoginRateLimiter;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Auth\Totp;
use MeldeVerkehr\Auth\WebAuthn\CborDecoder;
use MeldeVerkehr\Auth\WebAuthn\WebAuthnService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Cases\CaseLifecycleService;
use MeldeVerkehr\Cases\CaseStatus;
use MeldeVerkehr\Cases\CaseStatusMachine;
use MeldeVerkehr\Communication\AuthorityMessageClassifier;
use MeldeVerkehr\Communication\AuthorityReplyJobHandler;
use MeldeVerkehr\Communication\AuthorityReplyService;
use MeldeVerkehr\Communication\CommunicationService;
use MeldeVerkehr\Communication\CommunicationStorage;
use MeldeVerkehr\Communication\ReplyAddressService;
use MeldeVerkehr\Community\CommunityReleaseService;
use MeldeVerkehr\Community\CommunityService;
use MeldeVerkehr\Community\CommunitySocialService;
use MeldeVerkehr\Community\CommunityAbuseService;
use MeldeVerkehr\Community\ModerationService;
use MeldeVerkehr\Community\ReputationService;
use MeldeVerkehr\Config\Config;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Database\Connection;
use MeldeVerkehr\Database\MigrationRunner;
use MeldeVerkehr\Dispatch\AuthorityRoutingService;
use MeldeVerkehr\Dispatch\DispatchJobHandler;
use MeldeVerkehr\Dispatch\DispatchPackageService;
use MeldeVerkehr\Dispatch\DispatchService;
use MeldeVerkehr\Dispatch\DryRunDispatchTransport;
use MeldeVerkehr\Evidence\EvidenceImageProcessor;
use MeldeVerkehr\Evidence\EvidencePrivacyService;
use MeldeVerkehr\Evidence\EvidenceReviewService;
use MeldeVerkehr\Evidence\EvidenceService;
use MeldeVerkehr\Evidence\EvidenceStorage;
use MeldeVerkehr\Operations\CaseSearchService;
use MeldeVerkehr\Operations\DiagnosticsService;
use MeldeVerkehr\Operations\DocumentCenterService;
use MeldeVerkehr\Operations\ExportService;
use MeldeVerkehr\Operations\ExportStorage;
use MeldeVerkehr\Operations\NotificationService;
use MeldeVerkehr\Operations\RetentionService;
use MeldeVerkehr\Operations\WebPushService;
use MeldeVerkehr\Queue\JobQueue;
use MeldeVerkehr\Queue\JobWorker;
use MeldeVerkehr\Release\BackupService;
use MeldeVerkehr\Release\MaintenanceService;
use MeldeVerkehr\Release\ReleaseReadinessService;
use MeldeVerkehr\Release\ReleaseRepository;
use MeldeVerkehr\Release\RequestRateLimiter;
use MeldeVerkehr\Release\UpdateService;
use MeldeVerkehr\Routing\Router;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Env;
use MeldeVerkehr\Support\Uuid;
use MeldeVerkehr\Witness\FinalReviewService;
use MeldeVerkehr\Witness\NeutralNarrativeBuilder;
use MeldeVerkehr\Witness\WitnessService;

$basePath = dirname(__DIR__);
require $basePath . '/bootstrap/autoload.php';

Env::load($basePath . '/.env');
$config = new Config($basePath . '/config');
$config->load();

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "[PASS] {$message}" . PHP_EOL;
        return;
    }

    $failed++;
    echo "[FAIL] {$message}" . PHP_EOL;
};

try {
    $uuid = Uuid::v4();
    $assert((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid), 'UUID v4 format');

    $pdo = Connection::make((array) $config->get('database', []));
    $assert($pdo instanceof PDO, 'PDO connection');

    $runner = new MigrationRunner($pdo, $basePath . '/database/migrations');
    $runner->migrate();
    $statuses = $runner->status();
    $assert($statuses !== [] && !in_array(false, array_column($statuses, 'applied'), true), 'All migrations applied');

    $email = 'test-' . bin2hex(random_bytes(5)) . '@example.test';
    $auth = new AuthService($pdo);
    $user = $auth->register([
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => $email,
        'password' => 'VeryStrongTestPassword-123!',
    ]);
    $assert(isset($user['id']), 'User registration');

    $authenticated = $auth->authenticate($email, 'VeryStrongTestPassword-123!');
    $assert(is_array($authenticated) && $authenticated['id'] === $user['id'], 'User authentication');

    $permissions = new PermissionService($pdo);
    $assert($permissions->can((string) $user['id'], 'case.create'), 'USER permission assignment');
    $assert(!$permissions->can((string) $user['id'], 'admin.system'), 'USER denied admin permission');

    $caseAudit = new AuditLogger($pdo, 'test-audit-key');
    $caseService = new CaseService(
        $pdo,
        new AuthorizationService($permissions),
        new SecretCipher('test-app-key'),
        'test-app-key',
        $caseAudit
    );

    $caseA = $caseService->createDraft((string) $user['id']);
    $caseB = $caseService->createDraft((string) $user['id']);

    $assert(
        (bool) preg_match('/^OWI-\d{4}-\d{6}$/', (string) $caseA['case']['public_number']),
        'Case public number format'
    );
    $assert(
        $caseA['case']['public_number'] !== $caseB['case']['public_number'],
        'Case public numbers are unique'
    );
    $assert($caseA['case']['status'] === CaseStatus::DRAFT, 'New case starts as DRAFT');

    $machine = new CaseStatusMachine();
    $assert($machine->can(CaseStatus::DRAFT, CaseStatus::CAPTURE_IN_PROGRESS), 'Valid draft transition');
    $assert($machine->can(CaseStatus::READY_FOR_REVIEW, CaseStatus::WAITING_FOR_EVIDENCE), 'Review can advance to evidence');
    $assert(!$machine->can(CaseStatus::DRAFT, CaseStatus::SENT), 'Invalid draft to sent transition rejected');

    $caseId = (string) $caseA['case']['id'];

    $caseService->saveVehicle((string) $user['id'], $caseId, [
        'license_plate' => 'GI-AB 123',
        'vehicle_type' => 'PKW',
        'color' => 'Schwarz',
    ]);
    $afterVehicle = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(($afterVehicle['vehicle']['license_plate'] ?? null) === 'GI-AB 123', 'Vehicle plate encrypt/decrypt roundtrip');
    $assert(($afterVehicle['case']['status'] ?? null) === CaseStatus::CAPTURE_IN_PROGRESS, 'Vehicle starts capture progress');

    $caseService->saveLocation((string) $user['id'], $caseId, [
        'street' => 'Teststraße',
        'house_number' => '1',
        'postal_code' => '35390',
        'city' => 'Gießen',
        'traffic_space_type' => 'SIDEWALK',
        'access_type' => 'PUBLIC',
    ]);
    $afterLocation = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(($afterLocation['location']['city'] ?? null) === 'Gießen', 'Location saved');

    $caseService->saveObservation((string) $user['id'], $caseId, [
        'observed_from' => '2026-09-24T10:00',
        'observed_until' => '2026-09-24T10:15',
        'obstruction' => '1',
        'endangerment' => null,
        'damage' => null,
    ]);
    $afterObservation = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(
        ($afterObservation['case']['observation_duration_seconds'] ?? null) === 900,
        'Observation duration derived from start and end'
    );
    $assert(
        ($afterObservation['case']['observed_from_local'] ?? null) === '2026-09-24T10:00',
        'Observation time roundtrips in application timezone'
    );

    $plateSearch = $caseService->listOwned((string) $user['id'], null, 'GI-AB 123');
    $assert(
        count(array_filter(
            $plateSearch,
            static fn(array $row): bool => ($row['id'] ?? null) === $caseId
        )) === 1,
        'Own case searchable by plate hash'
    );

    $locationSearch = $caseService->listOwned((string) $user['id'], null, 'Teststraße');
    $assert(
        count(array_filter(
            $locationSearch,
            static fn(array $row): bool => ($row['id'] ?? null) === $caseId
        )) === 1,
        'Own case searchable by location'
    );

    $offenseId = Uuid::v4();
    $offenseVersionId = Uuid::v4();
    $testStableKey = 'TEST_CONCRETE_OFFENSE_' . bin2hex(random_bytes(4));
    $pdo->prepare(
        'INSERT INTO offenses (id, stable_key, category_key, active, created_at, updated_at)
         VALUES (:id, :stable_key, "OTHER", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute([
        'id' => $offenseId,
        'stable_key' => $testStableKey,
    ]);
    $pdo->prepare(
        'INSERT INTO offense_versions
         (id, offense_id, version, code, title, description, legal_reference, fine_amount, points,
          duration_requirement, requires_sign, requires_duration, supports_obstruction,
          supports_endangerment, supports_damage, valid_from, valid_until, created_at)
         VALUES
         (:id, :offense_id, 1, NULL, "Testtatbestand", "Nur automatisierter Test.", NULL, NULL, NULL,
          NULL, 0, 0, 0, 0, 0, NULL, NULL, UTC_TIMESTAMP())'
    )->execute([
        'id' => $offenseVersionId,
        'offense_id' => $offenseId,
    ]);

    $newerVersionId = Uuid::v4();
    $pdo->prepare(
        'INSERT INTO offense_versions
         (id, offense_id, version, code, title, description, legal_reference, fine_amount, points,
          duration_requirement, requires_sign, requires_duration, supports_obstruction,
          supports_endangerment, supports_damage, valid_from, valid_until, created_at)
         VALUES
         (:id, :offense_id, 2, NULL, "Testtatbestand Version 2", "Nur automatisierter Test.", NULL, NULL, NULL,
          NULL, 0, 0, 0, 0, 0, NULL, NULL, UTC_TIMESTAMP())'
    )->execute([
        'id' => $newerVersionId,
        'offense_id' => $offenseId,
    ]);

    $available = $caseService->availableOffenses();
    $matchingVersions = array_values(array_filter(
        $available,
        static function (array $row) use ($testStableKey): bool {
            return ($row['stable_key'] ?? null) === $testStableKey;
        }
    ));
    $assert(
        count($matchingVersions) === 1 && (int) $matchingVersions[0]['version'] === 2,
        'Available offenses expose latest version only'
    );

    $caseService->setPrimaryOffense((string) $user['id'], $caseId, $newerVersionId);
    $afterOffense = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(
        ($afterOffense['case']['status'] ?? null) === CaseStatus::READY_FOR_REVIEW,
        'Complete M2 core advances to READY_FOR_REVIEW'
    );

    $review = $caseService->reviewSummary((string) $user['id'], $caseId);
    $assert($review['ready'] === true, 'M2 core review reports complete data');
    $assert($review['warnings'] === [], 'Complete test data has no review warnings');

    $caseService->confirmCoreReview((string) $user['id'], $caseId, false);
    $afterReview = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(
        ($afterReview['case']['status'] ?? null) === CaseStatus::WAITING_FOR_EVIDENCE,
        'Confirmed M2 review advances to WAITING_FOR_EVIDENCE'
    );

    $evidenceSource = tempnam(sys_get_temp_dir(), 'mv-evidence-');
    if ($evidenceSource === false) {
        throw new RuntimeException('Could not create evidence test file.');
    }

    if (!extension_loaded('gd')) {
        throw new RuntimeException('GD extension is required for evidence derivative integration tests.');
    }

    $testImage = imagecreatetruecolor(320, 240);
    if (!$testImage instanceof GdImage) {
        throw new RuntimeException('Could not create evidence test image.');
    }

    $background = imagecolorallocate($testImage, 230, 230, 230);
    $foreground = imagecolorallocate($testImage, 25, 25, 25);
    imagefilledrectangle($testImage, 0, 0, 319, 239, $background);
    imagerectangle($testImage, 20, 20, 300, 220, $foreground);

    if (!imagepng($testImage, $evidenceSource, 6)) {
        imagedestroy($testImage);
        throw new RuntimeException('Could not write evidence test image.');
    }
    imagedestroy($testImage);

    $evidenceStorage = new EvidenceStorage($basePath . '/storage/app');
    $evidenceService = new EvidenceService(
        $pdo,
        new AuthorizationService($permissions),
        $evidenceStorage,
        new AuditLogger($pdo, 'test-audit-key'),
        new EvidenceImageProcessor($evidenceStorage)
    );

    $storedEvidence = $evidenceService->storeFile(
        (string) $user['id'],
        $caseId,
        $evidenceSource,
        'test.png',
        'OVERVIEW'
    );

    $assert(($storedEvidence['category'] ?? null) === 'OVERVIEW', 'Evidence category stored');
    $assert(($storedEvidence['mime_type'] ?? null) === 'image/png', 'Evidence MIME detected from content');
    $assert(($storedEvidence['quality_state'] ?? null) === 'RETAKE_RECOMMENDED', 'Tiny image receives retake recommendation');
    $assert(
        $evidenceService->verifyIntegrity((string) $user['id'], (string) $storedEvidence['id']),
        'Evidence SHA-256 integrity verified'
    );

    $pathStmt = $pdo->prepare(
        'SELECT storage_path FROM evidence_versions
         WHERE evidence_id = :id AND variant = "ORIGINAL" AND version_no = 1'
    );
    $pathStmt->execute(['id' => $storedEvidence['id']]);
    $storedPath = $pathStmt->fetchColumn();
    $assert(
        is_string($storedPath)
        && str_starts_with($storedPath, 'evidence/originals/' . $caseId . '/')
        && is_file($basePath . '/storage/app/' . $storedPath),
        'Evidence original stored in protected application storage'
    );

    $evidenceList = $evidenceService->listForCase((string) $user['id'], $caseId);
    $assert(count($evidenceList) === 1, 'Evidence appears in owned case list');
    $assert(
        (int) ($evidenceList[0]['has_working_copy'] ?? 0) === 1,
        'Evidence working copy is generated when GD is available'
    );

    $workingStmt = $pdo->prepare(
        'SELECT storage_path, sha256 FROM evidence_versions
         WHERE evidence_id = :id AND variant = "WORKING" AND version_no = 1'
    );
    $workingStmt->execute(['id' => $storedEvidence['id']]);
    $workingRow = $workingStmt->fetch();
    $assert(
        is_array($workingRow)
        && is_file($basePath . '/storage/app/' . $workingRow['storage_path'])
        && hash_equals(
            (string) $workingRow['sha256'],
            hash_file('sha256', $basePath . '/storage/app/' . $workingRow['storage_path'])
        ),
        'Evidence working copy is separately stored and hashed'
    );

    $fakeVisionProvider = new class($testStableKey) implements VisionProviderInterface {
        public function __construct(private readonly string $stableKey)
        {
        }

        public function name(): string
        {
            return 'fake_vision';
        }

        public function enabled(): bool
        {
            return true;
        }

        public function analyze(
            string $purpose,
            string $imagePath,
            string $mimeType,
            array $context = []
        ): array {
            if (!is_file($imagePath)) {
                throw new RuntimeException('Fake provider expected readable image.');
            }

            if ($purpose === 'PLATE_OCR') {
                return [
                    'suggestions' => [[
                        'type' => 'LICENSE_PLATE',
                        'value' => [
                            'plate' => 'GI-X 777',
                            'region' => ['x' => 0.20, 'y' => 0.30, 'width' => 0.30, 'height' => 0.15],
                        ],
                        'confidence' => 0.93,
                    ]],
                    'metadata' => ['model' => 'fake-test-v1'],
                ];
            }

            if ($purpose === 'TRAFFIC_SIGNS') {
                return [
                    'suggestions' => [
                        [
                            'type' => 'TRAFFIC_SIGN',
                            'value' => [
                                'code' => 'TEST-SIGN-283',
                                'label' => 'Test-Verkehrszeichen',
                                'region' => ['x' => 0.10, 'y' => 0.10, 'width' => 0.20, 'height' => 0.30],
                            ],
                            'confidence' => 0.88,
                        ],
                        [
                            'type' => 'ADDITIONAL_SIGN',
                            'value' => ['text' => 'werktags 7-17 h'],
                            'confidence' => 0.77,
                        ],
                    ],
                    'metadata' => ['model' => 'fake-test-v1'],
                ];
            }

            if ($purpose === 'OFFENSE_SUGGESTIONS') {
                $ids = array_values(array_map(
                    static fn(array $signal): string => (string) $signal['suggestion_id'],
                    is_array($context['confirmed_signals'] ?? null) ? $context['confirmed_signals'] : []
                ));

                return [
                    'suggestions' => [[
                        'type' => 'OFFENSE',
                        'value' => [
                            'stable_key' => $this->stableKey,
                            'basis_suggestion_ids' => $ids,
                            'rationale' => 'Testvorschlag ausschließlich aus bestätigten Signalen.',
                        ],
                        'confidence' => 0.82,
                    ]],
                    'metadata' => ['model' => 'fake-test-v1'],
                ];
            }

            return ['suggestions' => [], 'metadata' => ['model' => 'fake-test-v1']];
        }
    };

    $assistService = new AssistService(
        $pdo,
        new AuthorizationService($permissions),
        $caseService,
        $evidenceStorage,
        new SecretCipher('test-app-key'),
        $fakeVisionProvider,
        new AuditLogger($pdo, 'test-audit-key'),
        new ImageQualityAnalyzer()
    );

    $qualityResult = $assistService->analyzeQuality(
        (string) $user['id'],
        (string) $storedEvidence['id']
    );
    $assert(
        (int) $qualityResult['version_no'] === 1
        && isset($qualityResult['metrics']['brightness_mean'])
        && isset($qualityResult['metrics']['contrast_stddev'])
        && isset($qualityResult['metrics']['sharpness_score']),
        'Local assist quality analysis stores deterministic metrics'
    );

    $qualityStoredStmt = $pdo->prepare(
        'SELECT overall_state, metrics_json
         FROM evidence_quality_metrics
         WHERE evidence_id = :id AND version_no = 1'
    );
    $qualityStoredStmt->execute(['id' => $storedEvidence['id']]);
    $qualityStored = $qualityStoredStmt->fetch();
    $assert(
        is_array($qualityStored)
        && in_array($qualityStored['overall_state'], ['SUITABLE','LIMITED','RETAKE_RECOMMENDED'], true)
        && is_array(json_decode((string) $qualityStored['metrics_json'], true)),
        'Local quality metrics are versioned in database'
    );

    $plateRun = $assistService->analyzeEvidence(
        (string) $user['id'],
        (string) $storedEvidence['id'],
        'PLATE_OCR'
    );
    $assert(
        $plateRun['status'] === 'COMPLETED' && (int) $plateRun['suggestion_count'] === 1,
        'Fake OCR provider produces one plate suggestion'
    );

    $assistOverview = $assistService->overview((string) $user['id'], $caseId);
    $plateSuggestion = array_values(array_filter(
        $assistOverview['suggestions'],
        static fn(array $row): bool => $row['suggestion_type'] === 'LICENSE_PLATE'
    ))[0] ?? null;
    $assert(
        is_array($plateSuggestion)
        && ($plateSuggestion['value']['plate'] ?? null) === 'GI-X 777'
        && abs(((float) $plateSuggestion['confidence']) - 0.93) < 0.0001,
        'Plate suggestion is normalized and readable to owner'
    );

    $encryptedSuggestionStmt = $pdo->prepare(
        'SELECT value_encrypted FROM assist_suggestions WHERE id = :id LIMIT 1'
    );
    $encryptedSuggestionStmt->execute(['id' => $plateSuggestion['id']]);
    $encryptedSuggestion = (string) $encryptedSuggestionStmt->fetchColumn();
    $assert(
        !str_contains($encryptedSuggestion, 'GI-X 777'),
        'Assist suggestion payload is encrypted at rest'
    );

    $assistService->decideSuggestion(
        (string) $user['id'],
        (string) $plateSuggestion['id'],
        true
    );
    $afterPlateConfirm = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(
        ($afterPlateConfirm['vehicle']['license_plate'] ?? null) === 'GI-AB 123',
        'Confirming OCR suggestion does not overwrite plate'
    );

    $assistService->applyPlateSuggestion(
        (string) $user['id'],
        (string) $plateSuggestion['id']
    );
    $afterPlateApply = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(
        ($afterPlateApply['vehicle']['license_plate'] ?? null) === 'GI-X 777',
        'Explicit apply step changes plate'
    );
    $assert(
        ($afterPlateApply['case']['status'] ?? null) === CaseStatus::READY_FOR_REVIEW,
        'Applying plate suggestion forces core review again'
    );
    $caseService->confirmCoreReview((string) $user['id'], $caseId, false);

    $signRun = $assistService->analyzeEvidence(
        (string) $user['id'],
        (string) $storedEvidence['id'],
        'TRAFFIC_SIGNS'
    );
    $assert(
        (int) $signRun['suggestion_count'] === 2,
        'Fake vision provider produces sign and additional-sign suggestions'
    );

    $assistOverview = $assistService->overview((string) $user['id'], $caseId);
    $signSuggestion = array_values(array_filter(
        $assistOverview['suggestions'],
        static fn(array $row): bool => $row['suggestion_type'] === 'TRAFFIC_SIGN' && $row['status'] === 'PENDING'
    ))[0] ?? null;
    $additionalSuggestion = array_values(array_filter(
        $assistOverview['suggestions'],
        static fn(array $row): bool => $row['suggestion_type'] === 'ADDITIONAL_SIGN' && $row['status'] === 'PENDING'
    ))[0] ?? null;

    $assistService->decideSuggestion(
        (string) $user['id'],
        (string) $signSuggestion['id'],
        true
    );
    $assistService->decideSuggestion(
        (string) $user['id'],
        (string) $additionalSuggestion['id'],
        false
    );

    $offenseRun = $assistService->analyzeEvidence(
        (string) $user['id'],
        (string) $storedEvidence['id'],
        'OFFENSE_SUGGESTIONS'
    );
    $assert(
        (int) $offenseRun['suggestion_count'] === 1,
        'Offense suggestion can be generated from confirmed assist signals only'
    );

    $assistOverview = $assistService->overview((string) $user['id'], $caseId);
    $offenseSuggestion = array_values(array_filter(
        $assistOverview['suggestions'],
        static fn(array $row): bool => $row['suggestion_type'] === 'OFFENSE' && $row['status'] === 'PENDING'
    ))[0] ?? null;
    $assert(
        is_array($offenseSuggestion)
        && ($offenseSuggestion['value']['stable_key'] ?? null) === $testStableKey
        && count($offenseSuggestion['value']['basis_suggestion_ids'] ?? []) === 1,
        'Offense suggestion stores confirmed signal basis'
    );

    $assistService->decideSuggestion(
        (string) $user['id'],
        (string) $offenseSuggestion['id'],
        true
    );
    $beforeOffenseApply = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(
        ($beforeOffenseApply['offenses'][0]['stable_key'] ?? null) === $testStableKey,
        'Confirming offense suggestion does not silently mutate primary offense'
    );

    $assistService->applyOffenseSuggestion(
        (string) $user['id'],
        (string) $offenseSuggestion['id']
    );
    $afterOffenseApply = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(
        ($afterOffenseApply['case']['status'] ?? null) === CaseStatus::READY_FOR_REVIEW,
        'Applying offense suggestion forces core review again'
    );
    $caseService->confirmCoreReview((string) $user['id'], $caseId, false);

    $assistRunStmt = $pdo->prepare(
        'SELECT provider, purpose, status, input_sha256, output_sha256, metadata_json
         FROM assist_runs
         WHERE case_id = :case_id
         ORDER BY created_at'
    );
    $assistRunStmt->execute(['case_id' => $caseId]);
    $assistRuns = $assistRunStmt->fetchAll();
    $assert(
        count($assistRuns) === 3
        && count(array_filter(
            $assistRuns,
            static fn(array $row): bool =>
                $row['provider'] === 'fake_vision'
                && $row['status'] === 'COMPLETED'
                && !empty($row['input_sha256'])
                && !empty($row['output_sha256'])
        )) === 3,
        'Assist runs audit provider purpose and input/output hashes'
    );

    $removedEvidence = $evidenceService->storeFile(
        (string) $user['id'],
        $caseId,
        $evidenceSource,
        'remove-test.png',
        'CONTEXT'
    );
    $evidenceService->markRemoved((string) $user['id'], (string) $removedEvidence['id']);
    $removedList = $evidenceService->listForCase((string) $user['id'], $caseId);
    $removedRow = array_values(array_filter(
        $removedList,
        static fn(array $row): bool => ($row['id'] ?? null) === $removedEvidence['id']
    ))[0] ?? null;
    $assert(
        is_array($removedRow) && ($removedRow['status'] ?? null) === 'REMOVED',
        'Evidence removal is logical and retains original record'
    );

    $privacyService = new EvidencePrivacyService(
        $pdo,
        new AuthorizationService($permissions),
        $evidenceStorage,
        new EvidenceImageProcessor($evidenceStorage),
        new AuditLogger($pdo, 'test-audit-key')
    );
    $reviewService = new EvidenceReviewService(
        $pdo,
        new AuthorizationService($permissions),
        $evidenceStorage,
        new AuditLogger($pdo, 'test-audit-key'),
        $caseService
    );

    $beforePrivacy = $reviewService->summary((string) $user['id'], $caseId);
    $assert(
        $beforePrivacy['ready'] === false
        && count(array_filter(
            $beforePrivacy['missing'],
            static fn(string $message): bool => str_contains($message, 'Privacy-Prüfung')
        )) >= 1,
        'Unreviewed privacy blocks evidence package'
    );

    $regionId = $privacyService->addRegion(
        (string) $user['id'],
        (string) $storedEvidence['id'],
        'FACE',
        0.10,
        0.10,
        0.20,
        0.20
    );
    $privacyDetail = $privacyService->detail((string) $user['id'], (string) $storedEvidence['id']);
    $assert(
        count($privacyDetail['regions']) === 1 && $privacyDetail['review'] === null,
        'Privacy region invalidates previous review snapshot'
    );

    $privacyResult = $privacyService->confirmReview(
        (string) $user['id'],
        (string) $storedEvidence['id']
    );
    $assert(
        (int) $privacyResult['public_version_no'] === 1
        && (int) $privacyResult['region_count'] === 1,
        'Privacy review creates first PUBLIC version'
    );

    $publicPreview = $privacyService->preview(
        (string) $user['id'],
        (string) $storedEvidence['id'],
        'PUBLIC'
    );
    $assert(
        hash_equals((string) $publicPreview['sha256'], hash('sha256', (string) $publicPreview['body'])),
        'PUBLIC preview hash matches stored redacted copy'
    );

    $processingStmt = $pdo->prepare(
        'SELECT processing_json FROM evidence_versions
         WHERE evidence_id = :id AND variant = "PUBLIC" AND version_no = 1'
    );
    $processingStmt->execute(['id' => $storedEvidence['id']]);
    $processing = json_decode((string) $processingStmt->fetchColumn(), true);
    $assert(
        is_array($processing)
        && ($processing['source_variant'] ?? null) === 'WORKING'
        && !empty($processing['source_sha256'])
        && !empty($processing['regions_sha256']),
        'PUBLIC version records source and privacy provenance'
    );

    $afterPrivacy = $reviewService->summary((string) $user['id'], $caseId);
    $assert($afterPrivacy['ready'] === true, 'Confirmed privacy clears blocking evidence checks');
    $assert($afterPrivacy['warnings'] !== [], 'Evidence review exposes non-blocking quality/category warnings');

    $warningsBlocked = false;
    try {
        $reviewService->confirm((string) $user['id'], $caseId, false);
    } catch (DomainException $e) {
        $warningsBlocked = true;
    }
    $assert($warningsBlocked, 'Evidence warnings require explicit acknowledgement');

    $package = $reviewService->confirm((string) $user['id'], $caseId, true);
    $assert(
        (int) $package['package_version'] === 1 && (int) $package['item_count'] === 1,
        'Evidence review freezes versioned package with active evidence only'
    );

    $afterEvidenceReview = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(
        ($afterEvidenceReview['case']['status'] ?? null) === CaseStatus::READY_FOR_REVIEW
        && (int) ($afterEvidenceReview['evidence_package']['version_no'] ?? 0) === 1,
        'Frozen evidence package advances case to READY_FOR_REVIEW'
    );

    $packageStmt = $pdo->prepare(
        'SELECT manifest_json, manifest_sha256 FROM evidence_packages
         WHERE id = :id LIMIT 1'
    );
    $packageStmt->execute(['id' => $package['package_id']]);
    $packageRow = $packageStmt->fetch();
    $assert(
        is_array($packageRow)
        && hash_equals(
            (string) $packageRow['manifest_sha256'],
            hash('sha256', (string) $packageRow['manifest_json'])
        ),
        'Frozen evidence package manifest hash verifies'
    );

    $packageItemStmt = $pdo->prepare(
        'SELECT variant, version_no, sha256_snapshot FROM evidence_package_items
         WHERE package_id = :package_id AND evidence_id = :evidence_id'
    );
    $packageItemStmt->execute([
        'package_id' => $package['package_id'],
        'evidence_id' => $storedEvidence['id'],
    ]);
    $packageItem = $packageItemStmt->fetch();
    $assert(
        is_array($packageItem)
        && $packageItem['variant'] === 'PUBLIC'
        && (int) $packageItem['version_no'] === 1
        && hash_equals((string) $packageItem['sha256_snapshot'], (string) $publicPreview['sha256']),
        'Package item freezes exact PUBLIC version and hash'
    );

    $frozenEditBlocked = false;
    try {
        $evidenceService->markRemoved((string) $user['id'], (string) $storedEvidence['id']);
    } catch (DomainException $e) {
        $frozenEditBlocked = true;
    }
    $assert($frozenEditBlocked, 'Evidence edits are blocked after package freeze');

    $witnessCipher = new SecretCipher('test-app-key');
    $witnessService = new WitnessService(
        $pdo,
        new AuthorizationService($permissions),
        $caseService,
        $witnessCipher,
        new NeutralNarrativeBuilder('Europe/Berlin'),
        new AuditLogger($pdo, 'test-audit-key')
    );

    $observationStatement = $witnessService->saveObservation(
        (string) $user['id'],
        $caseId,
        [
            'observation_text' => 'Ich beobachtete das Fahrzeug während des dokumentierten Zeitraums an der angegebenen Stelle.',
            'impact_text' => 'Der Gehweg war im Bereich des Fahrzeugs nur eingeschränkt nutzbar.',
            'context_text' => 'Die Angaben beruhen auf meiner eigenen Wahrnehmung vor Ort.',
        ]
    );
    $assert(
        (int) $observationStatement['version_no'] === 1,
        'Own observation is stored as version 1'
    );

    $encryptedObservationStmt = $pdo->prepare(
        'SELECT observation_text FROM case_observation_statements WHERE id = :id LIMIT 1'
    );
    $encryptedObservationStmt->execute(['id' => $observationStatement['id']]);
    $encryptedObservation = (string) $encryptedObservationStmt->fetchColumn();
    $assert(
        $encryptedObservation !== $observationStatement['observation_text']
        && !str_contains($encryptedObservation, 'Ich beobachtete das Fahrzeug'),
        'Own observation is encrypted at rest'
    );

    $generatedNarrative = $witnessService->generateNarrative(
        (string) $user['id'],
        $caseId
    );
    $assert(
        (int) $generatedNarrative['version_no'] === 1
        && str_contains((string) $generatedNarrative['final_text'], 'Teststraße 1')
        && str_contains((string) $generatedNarrative['final_text'], 'abschließende Würdigung'),
        'Neutral narrative is generated from confirmed case facts'
    );

    $editedNarrative = $witnessService->saveNarrative(
        (string) $user['id'],
        $caseId,
        (string) $generatedNarrative['final_text'] . "\n\nZusätzliche sachliche Klarstellung durch den meldenden Nutzer."
    );
    $assert(
        (int) $editedNarrative['version_no'] === 2,
        'Edited narrative creates a new immutable version'
    );

    $encryptedNarrativeStmt = $pdo->prepare(
        'SELECT final_text FROM case_narratives WHERE id = :id LIMIT 1'
    );
    $encryptedNarrativeStmt->execute(['id' => $editedNarrative['id']]);
    $assert(
        (string) $encryptedNarrativeStmt->fetchColumn() !== $editedNarrative['final_text'],
        'Narrative text is encrypted at rest'
    );

    $witnessReport = $witnessService->createReport(
        (string) $user['id'],
        $caseId
    );
    $assert(
        (int) $witnessReport['version_no'] === 1
        && ($witnessReport['snapshot']['evidence_package']['version_no'] ?? null) === 1,
        'Witness report freezes current observation narrative and evidence package'
    );

    $reportStorageStmt = $pdo->prepare(
        'SELECT snapshot_json, snapshot_sha256 FROM witness_reports WHERE id = :id LIMIT 1'
    );
    $reportStorageStmt->execute(['id' => $witnessReport['id']]);
    $reportStorage = $reportStorageStmt->fetch();
    $assert(
        is_array($reportStorage)
        && !str_contains((string) $reportStorage['snapshot_json'], 'Teststraße')
        && hash_equals(
            (string) $reportStorage['snapshot_sha256'],
            hash(
                'sha256',
                json_encode(
                    $witnessReport['snapshot'],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                )
            )
        ),
        'Witness snapshot is encrypted at rest and hash matches canonical plaintext snapshot'
    );

    $declarationBlocked = false;
    try {
        $witnessService->confirmReport(
            (string) $user['id'],
            (string) $witnessReport['id'],
            false
        );
    } catch (InvalidArgumentException $e) {
        $declarationBlocked = true;
    }
    $assert($declarationBlocked, 'Witness report requires explicit electronic declaration');

    $confirmedReport = $witnessService->confirmReport(
        (string) $user['id'],
        (string) $witnessReport['id'],
        true,
        [
            'ip_hash' => hash('sha256', 'test-ip'),
            'user_agent_hash' => hash('sha256', 'test-agent'),
            'session_id_hash' => hash('sha256', 'test-session'),
        ]
    );
    $assert(
        $confirmedReport['confirmed_at'] !== null,
        'Witness report can be electronically confirmed'
    );

    $currentReport = $witnessService->currentConfirmedReport(
        (string) $user['id'],
        $caseId
    );
    $assert(
        is_array($currentReport)
        && is_array($currentReport['declaration'] ?? null),
        'Current confirmed witness report includes declaration'
    );

    $declarationStmt = $pdo->prepare(
        'SELECT metadata_json FROM case_declarations WHERE witness_report_id = :id LIMIT 1'
    );
    $declarationStmt->execute(['id' => $witnessReport['id']]);
    $declarationMetadata = json_decode((string) $declarationStmt->fetchColumn(), true);
    $assert(
        is_array($declarationMetadata)
        && isset($declarationMetadata['ip_hash'])
        && !isset($declarationMetadata['ip']),
        'Electronic declaration stores minimized hashed audit metadata'
    );

    $finalReviewService = new FinalReviewService(
        $pdo,
        new AuthorizationService($permissions),
        $caseService,
        $witnessService,
        new AuditLogger($pdo, 'test-audit-key')
    );
    $finalSummary = $finalReviewService->summary(
        (string) $user['id'],
        $caseId
    );
    $assert(
        $finalSummary['red'] === []
        && $finalSummary['ready'] === true,
        'Final review has no blocking items after confirmed witness report'
    );
    $assert(
        $finalSummary['yellow'] !== [],
        'Final review carries forward non-blocking evidence warnings'
    );

    $finalWarningsBlocked = false;
    try {
        $finalReviewService->confirm(
            (string) $user['id'],
            $caseId,
            false
        );
    } catch (DomainException $e) {
        $finalWarningsBlocked = true;
    }
    $assert(
        $finalWarningsBlocked,
        'Final review requires explicit acknowledgement of yellow warnings'
    );

    $finalResult = $finalReviewService->confirm(
        (string) $user['id'],
        $caseId,
        true
    );
    $assert(
        ($finalResult['status'] ?? null) === CaseStatus::READY_FOR_SUBMISSION,
        'Final review advances case to READY_FOR_SUBMISSION'
    );

    $afterFinalReview = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(
        ($afterFinalReview['case']['status'] ?? null) === CaseStatus::READY_FOR_SUBMISSION,
        'Case persists READY_FOR_SUBMISSION after M4 completion'
    );

    $qualityStmt = $pdo->prepare(
        'SELECT version_no, red_json, yellow_json, green_json, acknowledged_yellow, confirmed_at
         FROM case_quality_reviews WHERE case_id = :case_id ORDER BY version_no DESC LIMIT 1'
    );
    $qualityStmt->execute(['case_id' => $caseId]);
    $qualityReview = $qualityStmt->fetch();
    $assert(
        is_array($qualityReview)
        && (int) $qualityReview['version_no'] === 1
        && json_decode((string) $qualityReview['red_json'], true) === []
        && (int) $qualityReview['acknowledged_yellow'] === 1
        && $qualityReview['confirmed_at'] !== null,
        'Final quality review is versioned and stores traffic-light snapshot'
    );

    $authorityId = Uuid::v4();
    $endpointId = Uuid::v4();
    $requirementId = Uuid::v4();
    $routingRuleId = Uuid::v4();

    $pdo->prepare(
        'INSERT INTO authorities
         (id, name, authority_type, country_code, state_code, district, municipality, status,
          source_note, last_verified_at, created_at, updated_at)
         VALUES
         (:id, "Testbehörde Gießen", "TRAFFIC_ENFORCEMENT", "DE", NULL, NULL, "Gießen", "VERIFIED",
          "Nur automatisierter Integrationstest", UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute(['id' => $authorityId]);

    $pdo->prepare(
        'INSERT INTO authority_endpoints
         (id, authority_id, channel, endpoint_value, priority, status, max_total_bytes,
          last_verified_at, created_at, updated_at)
         VALUES
         (:id, :authority_id, "EMAIL", "authority@example.test", 10, "VERIFIED", 10485760,
          UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute(['id' => $endpointId, 'authority_id' => $authorityId]);

    $pdo->prepare(
        'INSERT INTO authority_requirement_versions
         (id, authority_id, version_no, required_fields_json, accepted_mime_json,
          max_attachment_bytes, max_total_bytes, notes, active, created_at)
         VALUES
         (:id, :authority_id, 1, :required_fields, :accepted_mime,
          5242880, 10485760, "Testprofil", 1, UTC_TIMESTAMP())'
    )->execute([
        'id' => $requirementId,
        'authority_id' => $authorityId,
        'required_fields' => json_encode([
            'reporter.first_name',
            'reporter.last_name',
            'reporter.email',
            'witness_report.snapshot.narrative.text',
        ], JSON_THROW_ON_ERROR),
        'accepted_mime' => json_encode(['image/png'], JSON_THROW_ON_ERROR),
    ]);

    $pdo->prepare(
        'INSERT INTO authority_routing_rules
         (id, authority_id, endpoint_id, country_code, state_code, postal_code, postal_prefix,
          city, district, offense_category, priority, certainty, active, created_at, updated_at)
         VALUES
         (:id, :authority_id, :endpoint_id, "DE", NULL, "35390", NULL,
          "Gießen", NULL, "OTHER", 200, "EXACT", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute([
        'id' => $routingRuleId,
        'authority_id' => $authorityId,
        'endpoint_id' => $endpointId,
    ]);

    $broadAuthorityId = Uuid::v4();
    $broadEndpointId = Uuid::v4();
    $broadRuleId = Uuid::v4();

    $pdo->prepare(
        'INSERT INTO authorities
         (id, name, authority_type, country_code, state_code, district, municipality, status,
          source_note, last_verified_at, created_at, updated_at)
         VALUES
         (:id, "Breite Testbehörde", "TRAFFIC_ENFORCEMENT", "DE", NULL, NULL, NULL, "VERIFIED",
          "Nur automatisierter Integrationstest", UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute(['id' => $broadAuthorityId]);

    $pdo->prepare(
        'INSERT INTO authority_endpoints
         (id, authority_id, channel, endpoint_value, priority, status, max_total_bytes,
          last_verified_at, created_at, updated_at)
         VALUES
         (:id, :authority_id, "EMAIL", "broad@example.test", 10, "VERIFIED", NULL,
          UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute(['id' => $broadEndpointId, 'authority_id' => $broadAuthorityId]);

    $pdo->prepare(
        'INSERT INTO authority_routing_rules
         (id, authority_id, endpoint_id, country_code, state_code, postal_code, postal_prefix,
          city, district, offense_category, priority, certainty, active, created_at, updated_at)
         VALUES
         (:id, :authority_id, :endpoint_id, "DE", NULL, NULL, NULL,
          NULL, NULL, NULL, 10, "LIKELY", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute([
        'id' => $broadRuleId,
        'authority_id' => $broadAuthorityId,
        'endpoint_id' => $broadEndpointId,
    ]);

    $dispatchAuthorization = new AuthorizationService($permissions);
    $routingService = new AuthorityRoutingService($pdo, $dispatchAuthorization);
    $route = $routingService->routeForCase((string) $user['id'], $caseId);
    $assert(
        $route['status'] === 'MATCHED'
        && ($route['selected']['authority_id'] ?? null) === $authorityId
        && ($route['selected']['certainty'] ?? null) === 'EXACT',
        'Authority routing selects the most specific exact rule'
    );

    $requirements = $routingService->requirements($authorityId);
    $assert(
        is_array($requirements)
        && (int) $requirements['version_no'] === 1
        && in_array('reporter.email', $requirements['required_fields'], true),
        'Authority requirement profile loads latest active version'
    );

    $dispatchPackageService = new DispatchPackageService(
        $pdo,
        $dispatchAuthorization,
        $caseService,
        $witnessService,
        $witnessCipher
    );
    $dispatchPreview = $dispatchPackageService->preview(
        (string) $user['id'],
        $caseId,
        $route['selected'],
        $requirements
    );
    $assert(
        $dispatchPreview['ready'] === true
        && $dispatchPreview['errors'] === [],
        'Dispatch package satisfies authority compatibility profile'
    );

    $dispatchQueue = new JobQueue($pdo);
    $dryRunTransport = new DryRunDispatchTransport($pdo);
    $replyAddressService = new ReplyAddressService(
        $pdo,
        'reply.invalid',
        'reply',
        'test-app-key'
    );

    $dispatchService = new DispatchService(
        $pdo,
        $dispatchAuthorization,
        $caseService,
        $routingService,
        $dispatchPackageService,
        $dispatchQueue,
        $dryRunTransport,
        $replyAddressService,
        $evidenceStorage,
        new AuditLogger($pdo, 'test-audit-key')
    );

    $dispatchReview = $dispatchService->review((string) $user['id'], $caseId);
    $assert(
        $dispatchReview['ready'] === true
        && $dispatchReview['warnings'] === [],
        'Citizen dispatch review is ready for exact verified test route'
    );

    $queuedDispatch = $dispatchService->queueDispatch(
        (string) $user['id'],
        $caseId,
        true,
        true
    );
    $assert(
        ($queuedDispatch['status'] ?? null) === 'QUEUED',
        'Dispatch is queued only after explicit citizen confirmation'
    );

    $afterDispatchQueue = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(
        ($afterDispatchQueue['case']['status'] ?? null) === CaseStatus::SUBMISSION_PENDING,
        'Queued dispatch advances case to SUBMISSION_PENDING'
    );

    $dispatchPackageStmt = $pdo->prepare(
        'SELECT manifest_encrypted, manifest_sha256
         FROM dispatch_packages WHERE id = :id LIMIT 1'
    );
    $dispatchPackageStmt->execute(['id' => $queuedDispatch['package_id']]);
    $dispatchPackageStorage = $dispatchPackageStmt->fetch();
    $assert(
        is_array($dispatchPackageStorage)
        && !str_contains((string) $dispatchPackageStorage['manifest_encrypted'], 'Teststraße')
        && !str_contains((string) $dispatchPackageStorage['manifest_encrypted'], 'authority@example.test'),
        'Dispatch manifest is encrypted at rest'
    );

    $loadedDispatchPackage = $dispatchPackageService->load((string) $queuedDispatch['package_id']);
    $loadedDispatchJson = json_encode(
        $loadedDispatchPackage['manifest'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    $assert(
        hash_equals(
            (string) $loadedDispatchPackage['manifest_sha256'],
            hash('sha256', $loadedDispatchJson)
        ),
        'Dispatch package plaintext verifies against stored SHA-256'
    );

    $dispatchWorker = new JobWorker(
        $dispatchQueue,
        [new DispatchJobHandler($dispatchService)]
    );
    $dispatchWorkerResult = $dispatchWorker->run('dispatch-test-worker', 1);
    $assert(
        $dispatchWorkerResult['processed'] === 1
        && $dispatchWorkerResult['errors'] === 0,
        'Dispatch queue worker processes dry-run transport job'
    );

    $dispatchRowStmt = $pdo->prepare(
        'SELECT status, sent_at, last_error FROM dispatches WHERE id = :id LIMIT 1'
    );
    $dispatchRowStmt->execute(['id' => $queuedDispatch['dispatch_id']]);
    $dispatchRow = $dispatchRowStmt->fetch();
    $assert(
        is_array($dispatchRow)
        && $dispatchRow['status'] === 'SENT'
        && $dispatchRow['sent_at'] !== null
        && $dispatchRow['last_error'] === null,
        'Accepted dry-run transport records dispatch as SENT'
    );

    $afterDispatch = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(
        ($afterDispatch['case']['status'] ?? null) === CaseStatus::SENT,
        'Accepted dispatch advances case to SENT'
    );

    $outboxStmt = $pdo->prepare(
        'SELECT recipient, body_sha256, attachment_manifest_json
         FROM dispatch_dry_run_outbox WHERE dispatch_id = :dispatch_id LIMIT 1'
    );
    $outboxStmt->execute(['dispatch_id' => $queuedDispatch['dispatch_id']]);
    $outbox = $outboxStmt->fetch();
    $outboxAttachments = is_array($outbox)
        ? json_decode((string) $outbox['attachment_manifest_json'], true)
        : null;
    $assert(
        is_array($outbox)
        && $outbox['recipient'] === 'authority@example.test'
        && is_array($outboxAttachments)
        && count($outboxAttachments) === 1
        && ($outboxAttachments[0]['sha256'] ?? null) === $publicPreview['sha256'],
        'Dry-run outbox records recipient and verified attachment hashes without sending mail'
    );

    $attemptStmt = $pdo->prepare(
        'SELECT attempt_no, status, provider_reference
         FROM dispatch_attempts WHERE dispatch_id = :dispatch_id ORDER BY attempt_no DESC LIMIT 1'
    );
    $attemptStmt->execute(['dispatch_id' => $queuedDispatch['dispatch_id']]);
    $attempt = $attemptStmt->fetch();
    $assert(
        is_array($attempt)
        && (int) $attempt['attempt_no'] === 1
        && $attempt['status'] === 'SENT'
        && str_starts_with((string) $attempt['provider_reference'], 'dryrun:'),
        'Dispatch attempt stores dry-run provider reference'
    );

    $dispatchMetaStmt = $pdo->prepare(
        'SELECT reply_address_id, outbound_message_id
         FROM dispatches WHERE id = :id LIMIT 1'
    );
    $dispatchMetaStmt->execute(['id' => $queuedDispatch['dispatch_id']]);
    $dispatchMeta = $dispatchMetaStmt->fetch();
    $assert(
        is_array($dispatchMeta)
        && $dispatchMeta['reply_address_id'] !== null
        && is_string($dispatchMeta['outbound_message_id'])
        && str_starts_with($dispatchMeta['outbound_message_id'], '<mv-'),
        'Initial dispatch binds reply address and outbound Message-ID'
    );

    $replyStmt = $pdo->prepare(
        'SELECT full_address FROM case_reply_addresses WHERE id = :id LIMIT 1'
    );
    $replyStmt->execute(['id' => $dispatchMeta['reply_address_id']]);
    $replyAddress = (string) $replyStmt->fetchColumn();
    $assert(
        $replyAddress === $queuedDispatch['reply_address']
        && str_ends_with($replyAddress, '@reply.invalid'),
        'Per-dispatch random reply address is persisted'
    );

    $outboxThreadStmt = $pdo->prepare(
        'SELECT reply_to, message_id, in_reply_to
         FROM dispatch_dry_run_outbox
         WHERE dispatch_id = :dispatch_id
         ORDER BY id ASC LIMIT 1'
    );
    $outboxThreadStmt->execute(['dispatch_id' => $queuedDispatch['dispatch_id']]);
    $outboxThread = $outboxThreadStmt->fetch();
    $assert(
        is_array($outboxThread)
        && $outboxThread['reply_to'] === $replyAddress
        && $outboxThread['message_id'] === $dispatchMeta['outbound_message_id']
        && $outboxThread['in_reply_to'] === null,
        'Initial dry-run transport records Reply-To and Message-ID'
    );

    $classifier = new AuthorityMessageClassifier('Europe/Berlin');
    $bounceClassification = $classifier->classify(
        'Mail delivery failed',
        'Delivery Status Notification (Failure): recipient address rejected.',
        '2026-09-24 08:20:00'
    );
    $assert(
        ($bounceClassification['classification'] ?? null) === 'DELIVERY_FAILURE',
        'Deterministic classifier recognizes delivery failure notices'
    );

    $communicationStorage = new CommunicationStorage($basePath . '/storage/app');
    $communicationService = new CommunicationService(
        $pdo,
        $dispatchAuthorization,
        $caseService,
        $replyAddressService,
        $classifier,
        $witnessCipher,
        $communicationStorage,
        new AuditLogger($pdo, 'test-audit-key')
    );

    $inboundMail = [
        'source_id' => '1001',
        'message_id' => '<authority-reply-1@example.test>',
        'in_reply_to' => $dispatchMeta['outbound_message_id'],
        'from' => 'authority@example.test',
        'to' => [$replyAddress],
        'subject' => 'Rückfrage mit Frist bis 30.09.2026',
        'text' => 'Bitte ergänzen Sie weitere Angaben und antworten Sie bis spätestens 30.09.2026.',
        'received_at' => '2026-09-24 08:30:00',
        'attachments' => [
            [
                'filename' => 'anforderung.pdf',
                'mime_type' => 'application/pdf',
                'content' => '%PDF-1.4 integration-test',
            ],
        ],
    ];

    $ingested = $communicationService->ingest($inboundMail);
    $assert(
        ($ingested['status'] ?? null) === 'INGESTED'
        && ($ingested['classification'] ?? null) === 'INQUIRY'
        && ($ingested['deadline_at'] ?? null) === '2026-09-30 21:59:59',
        'Inbound authority inquiry is routed while its deadline is extracted separately'
    );

    $afterInbound = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(
        ($afterInbound['case']['status'] ?? null) === CaseStatus::USER_ACTION_REQUIRED,
        'Inbound deadline mail advances case to USER_ACTION_REQUIRED'
    );

    $duplicateInbound = $communicationService->ingest($inboundMail);
    $assert(
        ($duplicateInbound['status'] ?? null) === 'DUPLICATE'
        && ($duplicateInbound['message_id'] ?? null) === $ingested['message_id'],
        'Inbound Message-ID deduplication prevents duplicate case messages'
    );

    $communication = $communicationService->listForCase((string) $user['id'], $caseId);
    $inboundMessages = array_values(array_filter(
        $communication['messages'],
        static fn(array $message): bool => $message['direction'] === 'INBOUND'
    ));
    $assert(
        count($inboundMessages) === 1
        && $inboundMessages[0]['sender'] === 'authority@example.test'
        && str_contains($inboundMessages[0]['body_text'], '30.09.2026')
        && count($inboundMessages[0]['attachments']) === 1,
        'Communication timeline decrypts one deduplicated inbound message with attachment'
    );
    $assert(
        count(array_filter(
            $communication['tasks'],
            static fn(array $task): bool => $task['status'] === 'OPEN' && $task['task_type'] === 'INQUIRY'
        )) === 1
        && count(array_filter(
            $communication['deadlines'],
            static fn(array $deadline): bool => $deadline['status'] === 'OPEN'
        )) === 1,
        'Inbound inquiry with deadline creates one inquiry task and one deadline'
    );

    $attachmentId = (string) $inboundMessages[0]['attachments'][0]['id'];
    $attachment = $communicationService->attachment((string) $user['id'], $attachmentId);
    $assert(
        $attachment['filename'] === 'anforderung.pdf'
        && hash_equals($attachment['sha256'], hash('sha256', $attachment['body'])),
        'Inbound attachment is protected and hash-verified on retrieval'
    );

    $quarantined = $communicationService->ingest([
        'source_id' => '1002',
        'message_id' => '<unmatched@example.test>',
        'in_reply_to' => null,
        'from' => 'unknown@example.test',
        'to' => ['unknown@reply.invalid'],
        'subject' => 'Nicht zuordenbar',
        'text' => 'Diese Nachricht gehört zu keinem bekannten Vorgang.',
        'received_at' => '2026-09-24 08:45:00',
        'attachments' => [],
    ]);
    $assert(
        ($quarantined['status'] ?? null) === 'QUARANTINED',
        'Unmatched inbound mail is quarantined instead of discarded'
    );

    $quarantineStmt = $pdo->prepare(
        'SELECT sender_encrypted, body_text_encrypted
         FROM inbound_mail_quarantine WHERE id = :id LIMIT 1'
    );
    $quarantineStmt->execute(['id' => $quarantined['quarantine_id']]);
    $quarantineRow = $quarantineStmt->fetch();
    $assert(
        is_array($quarantineRow)
        && !str_contains((string) $quarantineRow['sender_encrypted'], 'unknown@example.test')
        && !str_contains((string) $quarantineRow['body_text_encrypted'], 'keinem bekannten Vorgang'),
        'Quarantined mail content is encrypted at rest'
    );

    $replyService = new AuthorityReplyService(
        $pdo,
        $dispatchAuthorization,
        $caseService,
        $witnessCipher,
        $dispatchQueue,
        $dryRunTransport,
        'noreply@example.test',
        new AuditLogger($pdo, 'test-audit-key')
    );

    $draft = $replyService->createDraft(
        (string) $user['id'],
        (string) $ingested['message_id']
    );
    $assert(
        (int) $draft['version_no'] === 1
        && $draft['status'] === 'DRAFT'
        && str_contains($draft['body'], 'Bitte ergänzen oder konkretisieren'),
        'Authority reply assistant creates editable deterministic draft'
    );

    $draftStorageStmt = $pdo->prepare(
        'SELECT body_encrypted FROM authority_reply_drafts WHERE id = :id LIMIT 1'
    );
    $draftStorageStmt->execute(['id' => $draft['id']]);
    $assert(
        !str_contains((string) $draftStorageStmt->fetchColumn(), 'Bitte ergänzen oder konkretisieren'),
        'Authority reply draft is encrypted at rest'
    );

    $draftV2 = $replyService->saveDraft(
        (string) $user['id'],
        (string) $draft['id'],
        $draft['body'] . "\n\nErgänzung für den Integrationstest."
    );
    $assert(
        (int) $draftV2['version_no'] === 2,
        'Edited authority reply creates a new version'
    );

    $unconfirmedReplyBlocked = false;
    try {
        $replyService->queueSend(
            (string) $user['id'],
            (string) $draftV2['id'],
            false
        );
    } catch (InvalidArgumentException $e) {
        $unconfirmedReplyBlocked = true;
    }
    $assert(
        $unconfirmedReplyBlocked,
        'Authority reply cannot enter queue without explicit user confirmation'
    );

    $queuedReply = $replyService->queueSend(
        (string) $user['id'],
        (string) $draftV2['id'],
        true
    );
    $assert(
        ($queuedReply['status'] ?? null) === 'QUEUED',
        'Confirmed authority reply is queued'
    );

    $replyWorker = new JobWorker(
        $dispatchQueue,
        [new AuthorityReplyJobHandler($replyService)]
    );
    $replyWorkerResult = $replyWorker->run('reply-test-worker', 1);
    $assert(
        $replyWorkerResult['processed'] === 1
        && $replyWorkerResult['errors'] === 0,
        'Reply queue processes confirmed draft through dry-run transport'
    );

    $sentDraft = $replyService->latestForMessage(
        (string) $user['id'],
        (string) $ingested['message_id']
    );
    $assert(
        is_array($sentDraft)
        && $sentDraft['status'] === 'SENT'
        && $sentDraft['sent_at'] !== null,
        'Reply draft is marked SENT after accepted transport'
    );

    $replyOutboxStmt = $pdo->prepare(
        'SELECT recipient, reply_to, message_id, in_reply_to, body_sha256
         FROM dispatch_dry_run_outbox
         WHERE dispatch_id = :dispatch_id
         ORDER BY id DESC LIMIT 1'
    );
    $replyOutboxStmt->execute(['dispatch_id' => $queuedDispatch['dispatch_id']]);
    $replyOutbox = $replyOutboxStmt->fetch();
    $assert(
        is_array($replyOutbox)
        && $replyOutbox['recipient'] === 'authority@example.test'
        && $replyOutbox['reply_to'] === $replyAddress
        && $replyOutbox['in_reply_to'] === '<authority-reply-1@example.test>'
        && str_starts_with((string) $replyOutbox['message_id'], '<mv-reply-'),
        'Dry-run authority reply preserves recipient Reply-To Message-ID and In-Reply-To'
    );

    $communicationAfterReply = $communicationService->listForCase((string) $user['id'], $caseId);
    $outboundReplies = array_values(array_filter(
        $communicationAfterReply['messages'],
        static fn(array $message): bool => $message['direction'] === 'OUTBOUND'
    ));
    $assert(
        count($outboundReplies) === 1
        && $outboundReplies[0]['classification'] === 'OUTBOUND_REPLY'
        && str_contains($outboundReplies[0]['body_text'], 'Ergänzung für den Integrationstest'),
        'Confirmed authority reply is stored as encrypted outbound timeline message'
    );

    $afterReply = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(
        ($afterReply['case']['status'] ?? null) === CaseStatus::AUTHORITY_PROCESSING,
        'Successful user reply returns case to AUTHORITY_PROCESSING'
    );

    $remainingOpenTasks = array_filter(
        $communicationAfterReply['tasks'],
        static fn(array $task): bool => $task['status'] === 'OPEN'
    );
    $assert(
        count($remainingOpenTasks) === 0,
        'Successful authority reply completes tasks derived from source message'
    );

    $openDeadline = array_values(array_filter(
        $communicationAfterReply['deadlines'],
        static fn(array $deadline): bool => $deadline['status'] === 'OPEN'
    ))[0] ?? null;
    $assert(is_array($openDeadline), 'Authority deadline remains visible until explicitly resolved');

    if (is_array($openDeadline)) {
        $resolvedCaseId = $communicationService->resolveDeadline(
            (string) $user['id'],
            (string) $openDeadline['id']
        );
        $assert($resolvedCaseId === $caseId, 'User can explicitly resolve tracked authority deadline');
    }

    @unlink($evidenceSource);

    $other = $auth->register([
        'first_name' => 'Other',
        'last_name' => 'User',
        'email' => 'other-' . bin2hex(random_bytes(5)) . '@example.test',
        'password' => 'VeryStrongOtherPassword-123!',
    ]);
    $foreignBlocked = false;
    try {
        $caseService->findOwned((string) $other['id'], $caseId);
    } catch (Throwable $e) {
        $foreignBlocked = true;
    }
    $assert($foreignBlocked, 'Foreign user cannot read another user case');

    $foreignEvidenceBlocked = false;
    try {
        $evidenceService->findOwned((string) $other['id'], (string) $storedEvidence['id']);
    } catch (Throwable $e) {
        $foreignEvidenceBlocked = true;
    }
    $assert($foreignEvidenceBlocked, 'Foreign user cannot read another user evidence');

    $foreignPrivacyBlocked = false;
    try {
        $privacyService->detail((string) $other['id'], (string) $storedEvidence['id']);
    } catch (Throwable $e) {
        $foreignPrivacyBlocked = true;
    }
    $assert($foreignPrivacyBlocked, 'Foreign user cannot inspect another user privacy data');

    $analyticsAudit = new AuditLogger($pdo, 'test-audit-key');
    $mapAnalytics = new MapAnalyticsService(
        $pdo,
        new AuthorizationService($permissions),
        $caseService,
        $analyticsAudit
    );

    $mapCase1 = $caseService->createDraft((string) $user['id']);
    $mapCase1Id = (string) $mapCase1['case']['id'];
    $caseService->saveLocation((string) $user['id'], $mapCase1Id, [
        'latitude' => '50.5841000',
        'longitude' => '8.6781000',
        'street' => 'Kartenstraße',
        'house_number' => '1',
        'postal_code' => '35390',
        'city' => 'Gießen',
        'traffic_space_type' => 'ROADWAY',
        'access_type' => 'PUBLIC',
    ]);
    $caseService->saveObservation((string) $user['id'], $mapCase1Id, [
        'observed_from' => '2026-09-20T12:00',
        'observed_until' => '2026-09-20T12:10',
    ]);

    $mapCase2 = $caseService->createDraft((string) $user['id']);
    $mapCase2Id = (string) $mapCase2['case']['id'];
    $caseService->saveLocation((string) $user['id'], $mapCase2Id, [
        'latitude' => '50.5843000',
        'longitude' => '8.6783000',
        'street' => 'Kartenstraße',
        'house_number' => '3',
        'postal_code' => '35390',
        'city' => 'Gießen',
        'traffic_space_type' => 'ROADWAY',
        'access_type' => 'PUBLIC',
    ]);
    $caseService->saveObservation((string) $user['id'], $mapCase2Id, [
        'observed_from' => '2026-09-21T18:00',
        'observed_until' => '2026-09-21T18:05',
    ]);

    $foreignMapCase = $caseService->createDraft((string) $other['id']);
    $foreignMapCaseId = (string) $foreignMapCase['case']['id'];
    $caseService->saveLocation((string) $other['id'], $foreignMapCaseId, [
        'latitude' => '50.5842000',
        'longitude' => '8.6782000',
        'street' => 'Kartenstraße',
        'house_number' => '2',
        'postal_code' => '35390',
        'city' => 'Gießen',
        'traffic_space_type' => 'ROADWAY',
        'access_type' => 'PUBLIC',
    ]);

    $mapPoints = $mapAnalytics->mapData((string) $user['id']);
    $mapIds = array_column($mapPoints, 'case_id');
    $assert(
        in_array($mapCase1Id, $mapIds, true)
        && in_array($mapCase2Id, $mapIds, true)
        && !in_array($foreignMapCaseId, $mapIds, true),
        'Private map contains own geolocated cases and excludes foreign cases'
    );
    $assert(
        !str_contains(json_encode($mapPoints, JSON_THROW_ON_ERROR), 'license_plate'),
        'Private map data does not select license plate fields'
    );

    $hotspots = $mapAnalytics->hotspotCandidates((string) $user['id'], 2);
    $assert(
        $hotspots !== []
        && (int) $hotspots[0]['case_count'] >= 2
        && !in_array($foreignMapCaseId, $hotspots[0]['case_ids'], true),
        'Hotspot detection aggregates only own cases'
    );

    $area = $mapAnalytics->createProblemArea(
        (string) $user['id'],
        'Test-Problemstelle',
        50.5842,
        8.6782,
        200,
        'MANUAL',
        'Gießen',
        'Kartenstraße'
    );
    $assert(
        count($area['cases']) === 2,
        'Problem area automatically links nearby own cases only'
    );
    $assert(
        !array_key_exists('license_plate', $area['cases'][0] ?? []),
        'Problem area detail contains no license plate data'
    );

    $foreignAreaBlocked = false;
    try {
        $mapAnalytics->problemArea((string) $other['id'], (string) $area['id']);
    } catch (DomainException $e) {
        $foreignAreaBlocked = true;
    }
    $assert(
        $foreignAreaBlocked,
        'Problem area ownership blocks foreign user access'
    );

    $analyticsResult = $mapAnalytics->analytics(
        (string) $user['id'],
        new DateTimeImmutable('2026-09-01'),
        new DateTimeImmutable('2026-09-30')
    );
    $assert(
        (int) $analyticsResult['total'] >= 2,
        'Analytics aggregates own cases in requested period'
    );

    $municipalReport = $mapAnalytics->createMunicipalReport(
        (string) $user['id'],
        (string) $area['id'],
        new DateTimeImmutable('2026-09-01'),
        new DateTimeImmutable('2026-09-30')
    );
    $reportJson = json_encode(
        $municipalReport['report'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    $assert(
        ($municipalReport['report']['privacy']['contains_license_plates'] ?? true) === false
        && ($municipalReport['report']['privacy']['contains_vehicle_owner_data'] ?? true) === false
        && ($municipalReport['report']['privacy']['contains_case_ids'] ?? true) === false
        && ($municipalReport['report']['privacy']['contains_photos'] ?? true) === false,
        'Municipal problem report declares privacy-safe aggregate scope'
    );
    $assert(
        !str_contains($reportJson, $mapCase1Id)
        && !str_contains($reportJson, $mapCase2Id)
        && !str_contains($reportJson, $foreignMapCaseId),
        'Municipal problem report contains no internal case IDs'
    );

    $storedMunicipalReport = $mapAnalytics->municipalReport(
        (string) $user['id'],
        (string) $municipalReport['id']
    );
    $assert(
        hash_equals(
            (string) $storedMunicipalReport['report_sha256'],
            hash(
                'sha256',
                json_encode(
                    $storedMunicipalReport['report'],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                )
            )
        ),
        'Municipal problem report verifies against stored SHA-256'
    );

    $communityCipher = new SecretCipher('test-app-key');
    $communityService = new CommunityService(
        $pdo,
        new AuthorizationService($permissions),
        $communityCipher,
        new AuditLogger($pdo, 'test-audit-key')
    );
    $communitySocial = new CommunitySocialService(
        $pdo,
        new AuthorizationService($permissions),
        $communityCipher,
        new AuditLogger($pdo, 'test-audit-key'),
        new CommunityAbuseService($pdo)
    );
    $reputationService = new ReputationService($pdo);
    $communityReleaseService = new CommunityReleaseService(
        $pdo,
        $caseService,
        $evidenceStorage,
        new AuditLogger($pdo, 'test-audit-key')
    );
    $moderationService = new ModerationService($pdo, $permissions);

    $communityService->saveProfile((string) $user['id'], [
        'username' => 'testuser',
        'bio' => 'Öffentliche Test-Bio ohne private Kontaktdaten.',
        'region_state' => 'Hessen',
        'region_district' => 'Gießen',
        'region_city' => 'Gießen',
        'visibility_bio' => 'PUBLIC',
        'visibility_state' => 'PUBLIC',
        'visibility_district' => 'LOGGED_IN',
        'visibility_city' => 'PRIVATE',
        'leaderboard_opt_in' => true,
    ]);
    $communityService->saveProfile((string) $other['id'], [
        'username' => 'otheruser',
        'bio' => 'Andere Testperson.',
        'region_state' => 'Bayern',
        'region_district' => 'München',
        'region_city' => 'München',
        'visibility_bio' => 'PUBLIC',
        'visibility_state' => 'PUBLIC',
        'visibility_district' => 'LOGGED_IN',
        'visibility_city' => 'PRIVATE',
        'leaderboard_opt_in' => false,
    ]);

    $publicCommunityProfile = $communityService->profileByUsername(null, 'testuser');
    $assert(
        is_array($publicCommunityProfile)
        && ($publicCommunityProfile['username'] ?? null) === 'testuser'
        && ($publicCommunityProfile['region_state'] ?? null) === 'Hessen'
        && !array_key_exists('region_city', $publicCommunityProfile)
        && !array_key_exists('email', $publicCommunityProfile),
        'Community profile honors field visibility and never exposes account email'
    );

    $loggedCommunityProfile = $communityService->profileByUsername((string) $other['id'], 'testuser');
    $assert(
        is_array($loggedCommunityProfile)
        && ($loggedCommunityProfile['region_district'] ?? null) === 'Gießen'
        && !array_key_exists('region_city', $loggedCommunityProfile),
        'Logged-in profile view still respects private city field'
    );

    $group = $communityService->createGroup((string) $user['id'], [
        'group_type' => 'REGION',
        'name' => 'Gießen Verkehr',
        'description' => 'Regionale Testgruppe.',
        'region_level' => 'CITY',
        'region_code' => 'Gießen',
    ]);
    $communityService->joinGroup((string) $other['id'], (string) $group['id']);
    $groupList = $communityService->groups((string) $other['id']);
    $joinedGroup = array_values(array_filter(
        $groupList,
        static fn(array $row): bool => ($row['id'] ?? null) === $group['id']
    ))[0] ?? null;
    $assert(
        is_array($joinedGroup) && (int) $joinedGroup['joined'] === 1,
        'Community group membership is tracked'
    );

    $communityPost = $communityService->createPost((string) $user['id'], [
        'group_id' => $group['id'],
        'topic' => 'Testbeitrag',
        'body' => 'Sachlicher Community-Testbeitrag.',
    ]);
    $comment = $communityService->comment(
        (string) $other['id'],
        (string) $communityPost['id'],
        'Hilfreicher Kommentar zum Test.'
    );
    $assert(
        isset($comment['id']),
        'Community comments can be added by accessible users'
    );

    $communitySocial->followUsername((string) $other['id'], 'testuser');
    $followStats = $communitySocial->profileStats((string) $other['id'], (string) $user['id']);
    $assert(
        $followStats['is_following'] === true && $followStats['followers'] >= 1,
        'Community followers are persisted and exposed through profile stats'
    );

    $communitySocial->favorite((string) $other['id'], (string) $communityPost['id']);
    $assert(
        $communitySocial->isFavorite((string) $other['id'], (string) $communityPost['id'])
        && count($communitySocial->favorites((string) $other['id'])) >= 1,
        'Community post favorites are persisted and listed'
    );

    $groupMessageId = $communitySocial->sendGroupMessage(
        (string) $other['id'],
        (string) $group['id'],
        'Verschlüsselte Testnachricht im Gruppenchat.'
    );
    $groupChat = $communitySocial->groupChat((string) $user['id'], (string) $group['id']);
    $assert(
        $groupMessageId !== ''
        && count(array_filter(
            $groupChat['messages'],
            static fn(array $row): bool => ($row['id'] ?? null) === $groupMessageId
        )) === 1,
        'Group chat stores encrypted messages for active members'
    );

    $helpfulInserted = $communityService->reactHelpful(
        (string) $other['id'],
        (string) $communityPost['id']
    );
    $assert($helpfulInserted, 'Helpful reaction is stored once');
    $assert(
        $reputationService->awardHelpfulReaction(
            (string) $other['id'],
            (string) $communityPost['id']
        ),
        'Helpful reaction awards transparent community reputation once'
    );
    $assert(
        !$reputationService->awardHelpfulReaction(
            (string) $other['id'],
            (string) $communityPost['id']
        ),
        'Reputation unique key prevents reaction point gaming'
    );

    $score = $reputationService->score((string) $user['id']);
    $assert(
        $score['total'] >= 2
        && count($score['badges']) >= 1,
        'Community reputation and badges derive from transparent events'
    );

    $leaderboard = $reputationService->leaderboard('TOTAL');
    $assert(
        count(array_filter(
            $leaderboard,
            static fn(array $row): bool => ($row['username'] ?? null) === 'testuser'
        )) === 1
        && count(array_filter(
            $leaderboard,
            static fn(array $row): bool => ($row['username'] ?? null) === 'otheruser'
        )) === 0,
        'Leaderboard includes only explicit opt-in profiles'
    );

    $messageId = $communityService->sendMessageToUsername(
        (string) $user['id'],
        'otheruser',
        'Private Community-Testnachricht.'
    );
    $messageStorageStmt = $pdo->prepare(
        'SELECT body_encrypted, status FROM community_messages WHERE id = :id LIMIT 1'
    );
    $messageStorageStmt->execute(['id' => $messageId]);
    $messageStorage = $messageStorageStmt->fetch();
    $assert(
        is_array($messageStorage)
        && $messageStorage['status'] === 'REQUEST'
        && !str_contains((string) $messageStorage['body_encrypted'], 'Private Community-Testnachricht'),
        'First community DM is a request and encrypted at rest'
    );

    $communityService->acceptMessageRequest((string) $other['id'], $messageId);
    $replyMessageId = $communityService->sendMessageToUsername(
        (string) $other['id'],
        'testuser',
        'Angenommene Antwort.'
    );
    $replyStatusStmt = $pdo->prepare(
        'SELECT status FROM community_messages WHERE id = :id LIMIT 1'
    );
    $replyStatusStmt->execute(['id' => $replyMessageId]);
    $assert(
        $replyStatusStmt->fetchColumn() === 'ACCEPTED',
        'Accepted DM relationship allows subsequent messages without request state'
    );

    $communityService->blockUsername((string) $user['id'], 'otheruser');
    $blockedMessage = false;
    try {
        $communityService->sendMessageToUsername(
            (string) $other['id'],
            'testuser',
            'Diese Nachricht muss blockiert werden.'
        );
    } catch (DomainException $e) {
        $blockedMessage = true;
    }
    $assert($blockedMessage, 'Community block prevents DMs in both directions');

    $releaseDraft = $communityReleaseService->createDraft(
        (string) $user['id'],
        $caseId,
        [
            'public_text' => 'Anonymisierte Darstellung eines wiederkehrenden Verkehrsproblems.',
            'location_level' => 'STREET',
            'include_date' => true,
            'include_offense' => true,
        ],
        [(string) $storedEvidence['id']]
    );
    $releaseSnapshotJson = json_encode(
        $releaseDraft['snapshot'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    $privateCase = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(
        !str_contains($releaseSnapshotJson, $caseId)
        && !str_contains($releaseSnapshotJson, (string) $privateCase['case']['public_number'])
        && !str_contains($releaseSnapshotJson, 'GI-X 777')
        && !str_contains($releaseSnapshotJson, 'Teststraße 1'),
        'Community release snapshot excludes case IDs public case number plate and house number'
    );
    $assert(
        ($releaseDraft['snapshot']['privacy']['contains_license_plate'] ?? true) === false
        && ($releaseDraft['snapshot']['privacy']['evidence_variant'] ?? null) === 'PUBLIC',
        'Community release explicitly records sanitized PUBLIC evidence scope'
    );

    $publishedRelease = $communityReleaseService->publish(
        (string) $user['id'],
        (string) $releaseDraft['id']
    );
    $publicRelease = $communityReleaseService->publicRelease(
        (string) $publishedRelease['public_token']
    );
    $assert(
        is_array($publicRelease)
        && ($publicRelease['snapshot']['author']['username'] ?? null) === 'testuser',
        'Published sanitized release can be read by public token'
    );

    $publicReleaseImage = $communityReleaseService->publicEvidence(
        (string) $publishedRelease['public_token'],
        1
    );
    $assert(
        hash_equals((string) $publicReleaseImage['sha256'], (string) $publicPreview['sha256']),
        'Community release serves exact privacy-reviewed PUBLIC image hash'
    );

    $releasePost = $communityService->createPost((string) $user['id'], [
        'body' => 'Öffentliche anonymisierte Fallkopie zum Community-Test.',
        'case_release_id' => $publishedRelease['id'],
    ]);
    $assert(
        ($releasePost['case_release_id'] ?? null) === $publishedRelease['id'],
        'Community post can reference owned published sanitized release'
    );

    $moderator = $auth->register([
        'first_name' => 'Regional',
        'last_name' => 'Moderator',
        'email' => 'moderator-' . bin2hex(random_bytes(5)) . '@example.test',
        'password' => 'VeryStrongModeratorPassword-123!',
    ]);
    $pdo->prepare(
        'INSERT IGNORE INTO user_roles (user_id, role_id, created_at)
         SELECT :user_id, id, UTC_TIMESTAMP()
         FROM roles WHERE name = "REGIONAL_MODERATOR"'
    )->execute(['user_id' => $moderator['id']]);

    $communityService->saveProfile((string) $moderator['id'], [
        'username' => 'regionalmod',
        'region_state' => 'Hessen',
        'region_district' => 'Gießen',
        'region_city' => 'Gießen',
        'visibility_state' => 'PUBLIC',
        'visibility_district' => 'LOGGED_IN',
        'visibility_city' => 'PRIVATE',
        'leaderboard_opt_in' => false,
    ]);

    $hessenProblem = $communityService->createPublicProblemArea((string) $user['id'], [
        'name' => 'Hessische Testproblemstelle',
        'city' => 'Gießen',
        'district' => 'Gießen',
        'state' => 'Hessen',
        'latitude' => 50.58423,
        'longitude' => 8.67821,
        'radius_m' => 300,
    ]);
    $bayernProblem = $communityService->createPublicProblemArea((string) $other['id'], [
        'name' => 'Bayerische Testproblemstelle',
        'city' => 'München',
        'district' => 'München',
        'state' => 'Bayern',
        'latitude' => 48.1371,
        'longitude' => 11.5754,
        'radius_m' => 300,
    ]);

    $pendingProblems = $moderationService->pendingProblemAreas((string) $moderator['id']);
    $pendingIds = array_column($pendingProblems, 'id');
    $assert(
        in_array($hessenProblem['id'], $pendingIds, true)
        && !in_array($bayernProblem['id'], $pendingIds, true),
        'Regional moderator sees only pending public problems in own region scope'
    );

    $moderationService->approveProblemArea(
        (string) $moderator['id'],
        (string) $hessenProblem['id']
    );
    $reputationService->award(
        (string) $user['id'],
        'PROBLEM_REPORT',
        5,
        'PUBLIC_PROBLEM_APPROVED',
        'PUBLIC_PROBLEM_AREA',
        (string) $hessenProblem['id'],
        'problem-approved:' . $hessenProblem['id']
    );
    $approvedProblem = $communityService->publicProblemArea((string) $hessenProblem['id']);
    $assert(
        is_array($approvedProblem)
        && $approvedProblem['moderation_status'] === 'APPROVED'
        && abs(((float) $approvedProblem['generalized_latitude']) - 50.5842) < 0.00001,
        'Approved public problem area exposes only generalized coordinates'
    );

    $problemObservationId = $communityService->addPublicProblemObservation(
        (string) $user['id'],
        (string) $hessenProblem['id'],
        '2026-09-24',
        'OTHER',
        'Anonymisierte Beobachtung ohne Kennzeichen.'
    );
    $assert(
        $reputationService->award(
            (string) $user['id'],
            'PROBLEM_REPORT',
            1,
            'PUBLIC_PROBLEM_OBSERVATION',
            'PROBLEM_OBSERVATION',
            $problemObservationId,
            'problem-observation:' . $problemObservationId
        ),
        'Approved public problem observation can create transparent reputation event'
    );

    $reportId = $moderationService->report(
        (string) $other['id'],
        'POST',
        (string) $communityPost['id'],
        'PRIVACY',
        'Integrationstest für regionale Moderation.'
    );
    $moderationQueue = $moderationService->queue((string) $moderator['id']);
    $assert(
        count(array_filter(
            $moderationQueue,
            static fn(array $row): bool => ($row['id'] ?? null) === $reportId
        )) === 1,
        'Regional moderator receives reports for targets in own region'
    );

    $duplicateReportBlocked = false;
    try {
        $moderationService->report(
            (string) $other['id'],
            'POST',
            (string) $communityPost['id'],
            'PRIVACY',
            'Doppelte Meldung darf nicht erneut angelegt werden.'
        );
    } catch (DomainException $e) {
        $duplicateReportBlocked = true;
    }
    $assert($duplicateReportBlocked, 'Duplicate reports from same user and target are blocked');

    $queuedReport = array_values(array_filter(
        $moderationQueue,
        static fn(array $row): bool => ($row['id'] ?? null) === $reportId
    ))[0] ?? null;
    $assert(
        is_array($queuedReport) && (int) ($queuedReport['reporter_risk_score'] ?? 0) > 0,
        'Moderation queue exposes transparent reporter risk score'
    );

    $moderationService->resolve(
        (string) $moderator['id'],
        $reportId,
        'HIDE',
        'Integrationstest abgeschlossen.'
    );
    $assert(
        $communityService->post((string) $moderator['id'], (string) $communityPost['id']) === null,
        'Moderation HIDE removes reported post from public feed'
    );

    $appealable = $moderationService->appealableForUser((string) $user['id']);
    $assert(
        count(array_filter(
            $appealable,
            static fn(array $row): bool => ($row['id'] ?? null) === $reportId
        )) === 1,
        'Target owner can see appealable moderation decision'
    );

    $appealId = $moderationService->submitAppeal(
        (string) $user['id'],
        $reportId,
        'Der Beitrag enthält keine personenbezogenen Angaben und soll erneut geprüft werden.'
    );
    $appealQueue = $moderationService->appealsQueue((string) $moderator['id']);
    $assert(
        count(array_filter(
            $appealQueue,
            static fn(array $row): bool => ($row['id'] ?? null) === $appealId
        )) === 1,
        'Appeal enters scoped moderation queue'
    );

    $escalationId = $moderationService->escalate(
        (string) $moderator['id'],
        'APPEAL',
        $appealId,
        'HIGH',
        'Einspruch soll zusätzlich durch übergeordnete Moderation geprüft werden.'
    );

    $moderationAdmin = $auth->register([
        'first_name' => 'Moderation',
        'last_name' => 'Admin',
        'email' => 'moderation-admin-' . bin2hex(random_bytes(5)) . '@example.test',
        'password' => 'VeryStrongModerationAdmin-123!',
    ]);
    $pdo->prepare(
        'INSERT IGNORE INTO user_roles (user_id, role_id, created_at)
         SELECT :user_id, id, UTC_TIMESTAMP()
         FROM roles WHERE name = "SUPER_ADMIN"'
    )->execute(['user_id' => $moderationAdmin['id']]);

    $adminModeration = new ModerationService(
        $pdo,
        $permissions,
        new CommunityAbuseService($pdo),
        new AuditLogger($pdo, 'test-audit-key')
    );
    $openEscalations = $adminModeration->escalations((string) $moderationAdmin['id']);
    $assert(
        count(array_filter(
            $openEscalations,
            static fn(array $row): bool => ($row['id'] ?? null) === $escalationId
        )) === 1,
        'Senior moderation sees escalated appeal'
    );
    $adminModeration->resolveEscalation(
        (string) $moderationAdmin['id'],
        $escalationId,
        'Zusatzprüfung durchgeführt; Einspruch kann in der Fachmoderation entschieden werden.'
    );

    $moderationService->resolveAppeal(
        (string) $moderator['id'],
        $appealId,
        'OVERTURN',
        'Nach erneuter Prüfung wird die Ausblendung aufgehoben.'
    );
    $assert(
        $communityService->post((string) $moderator['id'], (string) $communityPost['id']) !== null,
        'Successful appeal restores target from captured pre-moderation state'
    );

    $abuseUser = $auth->register([
        'first_name' => 'Abuse',
        'last_name' => 'Test',
        'email' => 'abuse-' . bin2hex(random_bytes(5)) . '@example.test',
        'password' => 'VeryStrongAbuseTest-123!',
    ]);
    $abuseService = new CommunityAbuseService($pdo);
    $guardedCommunity = new CommunityService(
        $pdo,
        new AuthorizationService($permissions),
        $communityCipher,
        new AuditLogger($pdo, 'test-audit-key'),
        $abuseService
    );
    $guardedCommunity->saveProfile((string) $abuseUser['id'], [
        'username' => 'abusetest',
        'region_state' => 'Hessen',
        'region_district' => 'Gießen',
        'region_city' => 'Gießen',
        'visibility_state' => 'PUBLIC',
        'visibility_district' => 'LOGGED_IN',
        'visibility_city' => 'PRIVATE',
        'leaderboard_opt_in' => false,
    ]);
    $guardedCommunity->createPost((string) $abuseUser['id'], ['body' => 'Identischer automatisierter Testinhalt.']);
    $guardedCommunity->createPost((string) $abuseUser['id'], ['body' => 'Identischer automatisierter Testinhalt.']);

    $duplicateSpamBlocked = false;
    try {
        $guardedCommunity->createPost((string) $abuseUser['id'], ['body' => 'Identischer automatisierter Testinhalt.']);
    } catch (DomainException $e) {
        $duplicateSpamBlocked = true;
    }
    $assert($duplicateSpamBlocked, 'Duplicate-content anti-spam blocks repeated identical posts');

    for ($i = 0; $i < 5; $i++) {
        $abuseService->record(
            (string) $abuseUser['id'],
            'REPORT',
            'POST',
            Uuid::v4(),
            'Mass report ' . $i
        );
    }
    $abuseFlags = $moderationService->abuseFlags((string) $moderator['id']);
    $massFlag = array_values(array_filter(
        $abuseFlags,
        static fn(array $row): bool =>
            ($row['user_id'] ?? null) === $abuseUser['id']
            && ($row['signal_type'] ?? null) === 'MASS_REPORT_PATTERN'
    ))[0] ?? null;
    $assert(is_array($massFlag), 'Mass-report pattern creates moderator-reviewable abuse flag');

    $moderationService->resolveAbuseFlag(
        (string) $moderator['id'],
        (string) $massFlag['id'],
        'RESTRICT_REPORTING',
        'Automatisierter Test einer zeitlich begrenzten Reporting-Einschränkung.'
    );
    $reportingRestrictionWorks = false;
    try {
        $abuseService->assertAllowed((string) $abuseUser['id'], 'REPORT', 'Weitere Meldung');
    } catch (DomainException $e) {
        $reportingRestrictionWorks = true;
    }
    $assert($reportingRestrictionWorks, 'Resolved abuse flag can enforce temporary reporting restriction');

    $reputationGovernanceUser = $auth->register([
        'first_name' => 'Reputation',
        'last_name' => 'Governance',
        'email' => 'reputation-' . bin2hex(random_bytes(5)) . '@example.test',
        'password' => 'VeryStrongReputationTest-123!',
    ]);
    $communityService->saveProfile((string) $reputationGovernanceUser['id'], [
        'username' => 'reputationtest',
        'region_state' => 'Hessen',
        'region_district' => 'Gießen',
        'region_city' => 'Gießen',
        'visibility_state' => 'PUBLIC',
        'visibility_district' => 'LOGGED_IN',
        'visibility_city' => 'PRIVATE',
        'leaderboard_opt_in' => false,
    ]);

    $governedReputation = new ReputationService(
        $pdo,
        $permissions,
        new AuditLogger($pdo, 'test-audit-key')
    );

    for ($i = 1; $i <= 6; $i++) {
        $governedReputation->award(
            (string) $reputationGovernanceUser['id'],
            'COMMUNITY',
            2,
            'GOVERNANCE_DIMINISH_TEST',
            'TEST',
            (string) $i,
            'governance-diminish:' . $reputationGovernanceUser['id'] . ':' . $i
        );
    }

    $governedHistory = $governedReputation->history((string) $reputationGovernanceUser['id']);
    $sixthGovernedEvent = array_values(array_filter(
        $governedHistory,
        static fn(array $row): bool => ($row['source_id'] ?? null) === '6'
    ))[0] ?? null;
    $assert(
        is_array($sixthGovernedEvent)
        && (float) $sixthGovernedEvent['multiplier'] === 0.5
        && (int) $sixthGovernedEvent['effective_points'] === 1,
        'Reputation applies diminishing returns after configured full-rate event count'
    );

    $governedScore = $governedReputation->score((string) $reputationGovernanceUser['id']);
    $firstImpact = array_values(array_filter(
        $governedScore['achievements'],
        static fn(array $row): bool => ($row['achievement_key'] ?? null) === 'FIRST_IMPACT'
    ))[0] ?? null;
    $assert(
        is_array($firstImpact) && !empty($firstImpact['unlocked_at']),
        'Reputation achievements unlock from transparent event metrics'
    );

    for ($i = 1; $i <= 3; $i++) {
        $governedReputation->award(
            (string) $reputationGovernanceUser['id'],
            'PROBLEM_REPORT',
            10,
            'GOVERNANCE_CAP_TEST',
            'TEST',
            'cap-' . $i,
            'governance-cap:' . $reputationGovernanceUser['id'] . ':' . $i
        );
    }
    $capBlocked = !$governedReputation->award(
        (string) $reputationGovernanceUser['id'],
        'PROBLEM_REPORT',
        10,
        'GOVERNANCE_CAP_TEST',
        'TEST',
        'cap-4',
        'governance-cap:' . $reputationGovernanceUser['id'] . ':4'
    );
    $capScore = $governedReputation->score((string) $reputationGovernanceUser['id']);
    $assert(
        $capBlocked
        && ($capScore['daily']['PROBLEM_REPORT']['awarded_positive_points'] ?? 0) === 30,
        'Daily reputation cap prevents further positive farming while preserving event trace'
    );

    $reputationAnomalies = $governedReputation->anomalies((string) $moderationAdmin['id'], 'OPEN');
    $capAnomaly = array_values(array_filter(
        $reputationAnomalies,
        static fn(array $row): bool =>
            ($row['user_id'] ?? null) === $reputationGovernanceUser['id']
            && ($row['anomaly_type'] ?? null) === 'DAILY_CAP_REACHED'
    ))[0] ?? null;
    $assert(is_array($capAnomaly), 'Reputation cap produces reviewable anomaly without automatic punishment');

    $beforeCorrectionScore = $governedReputation->score((string) $reputationGovernanceUser['id'])['total'];
    $correctionId = $governedReputation->adminCorrectionByUsername(
        (string) $moderationAdmin['id'],
        'reputationtest',
        'COMMUNITY',
        7,
        'Integrationstest einer transparenten administrativen Punkte-Korrektur.'
    );
    $afterCorrectionScore = $governedReputation->score((string) $reputationGovernanceUser['id'])['total'];
    $assert(
        $afterCorrectionScore === $beforeCorrectionScore + 7
        && count(array_filter(
            $governedReputation->recentCorrections((string) $moderationAdmin['id']),
            static fn(array $row): bool => ($row['id'] ?? null) === $correctionId
        )) === 1,
        'Admin correction is appended as a separate auditable reputation event'
    );

    $governedReputation->resolveAnomaly(
        (string) $moderationAdmin['id'],
        (string) $capAnomaly['id'],
        'Tageslimit im Integrationstest erwartungsgemäß erreicht; keine weitere Maßnahme.'
    );
    $assert(
        count(array_filter(
            $governedReputation->anomalies((string) $moderationAdmin['id'], 'OPEN'),
            static fn(array $row): bool => ($row['id'] ?? null) === $capAnomaly['id']
        )) === 0,
        'Reputation anomaly can be explicitly reviewed and closed'
    );

    $caseSearch = new CaseSearchService(
        $pdo,
        new SecretCipher('test-app-key'),
        'test-app-key'
    );

    $plateSearch = $caseSearch->search((string) $user['id'], ['q' => 'GI-X 777']);
    $plateSearchIds = array_column($plateSearch['results'], 'id');
    $assert(
        in_array($caseId, $plateSearchIds, true)
        && !in_array($foreignMapCaseId, $plateSearchIds, true),
        'Global case search finds exact own plate and excludes foreign cases'
    );

    $foreignPlateSearch = $caseSearch->search((string) $other['id'], ['q' => 'GI-X 777']);
    $assert(
        count($foreignPlateSearch['results']) === 0,
        'Foreign user cannot discover owner case through global plate search'
    );

    $citySearch = $caseSearch->search((string) $user['id'], [
        'city' => 'Gießen',
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-30',
    ]);
    $assert(
        $citySearch['count'] >= 2,
        'Global case search supports city and date filters'
    );

    $savedFilter = $caseSearch->saveFilter(
        (string) $user['id'],
        'Gießen September',
        [
            'city' => 'Gießen',
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
        ]
    );
    $assert(
        ($savedFilter['name'] ?? null) === 'Gießen September'
        && (int) ($savedFilter['live_count'] ?? 0) >= 2,
        'Saved case filter stores normalized filters and live count'
    );
    $assert(
        $caseSearch->savedFilter((string) $other['id'], (string) $savedFilter['id']) === null,
        'Saved filters are private to their owner'
    );

    $notificationService = new NotificationService(
        $pdo,
        new SecretCipher('test-app-key')
    );
    $notificationCreated = $notificationService->create(
        (string) $user['id'],
        $caseId,
        'TEST_NOTIFICATION',
        'test-notification:' . $caseId,
        'HIGH',
        'Testbenachrichtigung',
        'Verschlüsselter Benachrichtigungstext.',
        '/cases/' . rawurlencode($caseId)
    );
    $assert($notificationCreated, 'In-app notification can be created');

    $notificationDuplicate = $notificationService->create(
        (string) $user['id'],
        $caseId,
        'TEST_NOTIFICATION',
        'test-notification:' . $caseId,
        'HIGH',
        'Testbenachrichtigung',
        'Verschlüsselter Benachrichtigungstext.',
        '/cases/' . rawurlencode($caseId)
    );
    $assert(!$notificationDuplicate, 'Notification unique key prevents duplicates');

    $notificationStorageStmt = $pdo->prepare(
        'SELECT id, body_encrypted FROM user_notifications
         WHERE user_id = :user_id AND unique_key = :unique_key LIMIT 1'
    );
    $notificationStorageStmt->execute([
        'user_id' => $user['id'],
        'unique_key' => 'test-notification:' . $caseId,
    ]);
    $notificationStorage = $notificationStorageStmt->fetch();
    $assert(
        is_array($notificationStorage)
        && !str_contains(
            (string) $notificationStorage['body_encrypted'],
            'Verschlüsselter Benachrichtigungstext'
        ),
        'Notification body is encrypted at rest'
    );

    $notificationList = $notificationService->list((string) $user['id']);
    $listedNotification = array_values(array_filter(
        $notificationList,
        static fn(array $row): bool => ($row['event_key'] ?? null) === 'TEST_NOTIFICATION'
    ))[0] ?? null;
    $assert(
        is_array($listedNotification)
        && ($listedNotification['body'] ?? null) === 'Verschlüsselter Benachrichtigungstext.',
        'Notification center decrypts own notification body'
    );
    $assert(
        count(array_filter(
            $notificationService->list((string) $other['id']),
            static fn(array $row): bool => ($row['event_key'] ?? null) === 'TEST_NOTIFICATION'
        )) === 0,
        'Notification center excludes foreign notifications'
    );

    $notificationService->markRead(
        (string) $user['id'],
        (string) $notificationStorage['id']
    );
    $readNotification = array_values(array_filter(
        $notificationService->list((string) $user['id']),
        static fn(array $row): bool => ($row['event_key'] ?? null) === 'TEST_NOTIFICATION'
    ))[0] ?? null;
    $assert(
        is_array($readNotification) && $readNotification['read_at'] !== null,
        'Notification can be marked read by owner'
    );

    $defaultPreferences = $notificationService->preferences((string) $user['id']);
    $assert(
        isset($defaultPreferences['CASE_TASK_OPEN'])
        && $defaultPreferences['CASE_TASK_OPEN']['in_app'] === true
        && $defaultPreferences['CASE_TASK_OPEN']['email'] === true
        && $defaultPreferences['CASE_TASK_OPEN']['push'] === true,
        'Notification channels default to enabled'
    );

    $notificationService->savePreference(
        (string) $user['id'],
        'CASE_TASK_OPEN',
        true,
        false,
        false
    );
    $savedChannelPreference = $notificationService->preference(
        (string) $user['id'],
        'CASE_TASK_OPEN'
    );
    $assert(
        $savedChannelPreference['in_app'] === true
        && $savedChannelPreference['email'] === false
        && $savedChannelPreference['push'] === false,
        'Notification channel preferences are persisted per event'
    );

    $testPush = new WebPushService(
        $pdo,
        new SecretCipher('test-app-key'),
        '',
        '',
        ''
    );
    $testPush->register(
        (string) $user['id'],
        'https://push.example.test/subscription/test',
        'p256dh-test',
        'auth-test',
        'Integration Test'
    );
    $assert(
        $testPush->activeCount((string) $user['id']) === 1,
        'Web push subscription is stored for the authenticated user'
    );
    $testPush->revokeAll((string) $user['id']);
    $assert(
        $testPush->activeCount((string) $user['id']) === 0,
        'Web push subscriptions can be revoked'
    );

    $notificationService->savePreference(
        (string) $user['id'],
        'TEST_DISABLED',
        false,
        true,
        true
    );
    $assert(
        !$notificationService->create(
            (string) $user['id'],
            null,
            'TEST_DISABLED',
            'disabled:' . $user['id'],
            'NORMAL',
            'Nicht anlegen',
            null,
            '/dashboard'
        ),
        'Disabled in-app preference prevents notification creation'
    );

    $documentCenter = new DocumentCenterService($pdo);
    $documentsBeforeExport = $documentCenter->list((string) $user['id']);
    $documentTypes = array_values(array_unique(array_column($documentsBeforeExport, 'type')));
    $assert(
        in_array('EVIDENCE_PACKAGE', $documentTypes, true)
        && in_array('WITNESS_REPORT', $documentTypes, true)
        && in_array('DISPATCH_PACKAGE', $documentTypes, true)
        && in_array('MUNICIPAL_REPORT', $documentTypes, true),
        'Document center aggregates existing owned artifacts'
    );
    $assert(
        count(array_filter(
            $documentCenter->list((string) $other['id']),
            static fn(array $row): bool => ($row['case_id'] ?? null) === $caseId
        )) === 0,
        'Document center excludes foreign case artifacts'
    );

    $exportStorage = new ExportStorage($basePath . '/storage/app');
    $exportService = new ExportService(
        $pdo,
        $caseSearch,
        $exportStorage,
        new AuditLogger($pdo, 'test-audit-key')
    );

    $safeExport = $exportService->createCaseExport(
        (string) $user['id'],
        'json',
        false,
        ['q' => 'GI-X 777']
    );
    $safeExportBinary = $exportService->binary(
        (string) $user['id'],
        (string) $safeExport['id']
    );
    $safeExportJson = json_decode((string) $safeExportBinary['body'], true, 512, JSON_THROW_ON_ERROR);
    $assert(
        is_array($safeExportJson)
        && ($safeExportJson['includes_sensitive'] ?? true) === false
        && !str_contains((string) $safeExportBinary['body'], 'GI-X 777')
        && !array_key_exists('license_plate', $safeExportJson['cases'][0] ?? []),
        'Default JSON export omits sensitive plate data'
    );

    $sensitiveExport = $exportService->createCaseExport(
        (string) $user['id'],
        'json',
        true,
        ['q' => 'GI-X 777']
    );
    $sensitiveExportBinary = $exportService->binary(
        (string) $user['id'],
        (string) $sensitiveExport['id']
    );
    $assert(
        str_contains((string) $sensitiveExportBinary['body'], 'GI-X 777'),
        'Sensitive export includes plate only after explicit opt-in'
    );

    $foreignExportBlocked = false;
    try {
        $exportService->binary(
            (string) $other['id'],
            (string) $sensitiveExport['id']
        );
    } catch (DomainException $e) {
        $foreignExportBlocked = true;
    }
    $assert($foreignExportBlocked, 'Export download enforces owner isolation');

    $documentsAfterExport = $documentCenter->list((string) $user['id'], 'EXPORT');
    $assert(
        count($documentsAfterExport) >= 2,
        'Document center includes newly generated exports'
    );

    $retentionService = new RetentionService($pdo, null, 7);
    $retentionPlan = $retentionService->planForUser((string) $user['id']);
    $retentionOverview = $retentionService->overview((string) $user['id']);
    $assert(
        $retentionPlan['automatic_case_deletion_enabled'] === false
        && $retentionOverview['closed_case_days'] === null
        && count(array_filter(
            $retentionOverview['schedules'],
            static fn(array $row): bool => ($row['data_type'] ?? null) === 'EXPORT'
        )) >= 2,
        'Retention plans temporary exports while automatic case deletion remains disabled'
    );

    $deletionPreview = $retentionService->accountDeletionPreview((string) $user['id']);
    $assert(
        isset($deletionPreview['review_required']['cases'])
        && str_contains((string) $deletionPreview['note'], 'nicht automatisch gelöscht'),
        'Account deletion preview separates review-required case data'
    );

    $diagnostics = (new DiagnosticsService(
        $pdo,
        new AuditLogger($pdo, 'test-audit-key'),
        $basePath . '/storage/app'
    ))->snapshot();
    $assert(
        ($diagnostics['audit']['ok'] ?? false) === true
        && ($diagnostics['storage']['exists'] ?? false) === true
        && (int) ($diagnostics['database']['migrations_total'] ?? 0) >= 20,
        'Operational diagnostics reports audit storage and migration health'
    );

    $pdo->prepare(
        'UPDATE export_artifacts
         SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
         WHERE id = :id'
    )->execute(['id' => $safeExport['id']]);
    $assert(
        $exportService->cleanupExpired() >= 1,
        'Expired export cleanup removes protected export artifacts'
    );

    $authorityAccess = new AuthorityAccessService($pdo, $permissions);
    $authorityPortal = new AuthorityPortalService(
        $pdo,
        $authorityAccess,
        new SecretCipher('test-app-key'),
        new AuditLogger($pdo, 'test-audit-key')
    );
    $authorityTokens = new AuthorityApiTokenService($pdo, $authorityAccess);
    $authorityHolders = new AuthorityHolderService(
        $pdo,
        $authorityAccess,
        new SecretCipher('test-app-key'),
        new AuditLogger($pdo, 'test-audit-key')
    );
    $authorityExports = new AuthorityExportService(
        $authorityPortal,
        $authorityAccess
    );

    $authoritySuper = $auth->register([
        'first_name' => 'Authority',
        'last_name' => 'Super',
        'email' => 'authority-super-' . bin2hex(random_bytes(5)) . '@example.test',
        'password' => 'VeryStrongAuthoritySuper-123!',
    ]);
    $pdo->prepare(
        'INSERT IGNORE INTO user_roles (user_id, role_id, created_at)
         SELECT :user_id, id, UTC_TIMESTAMP()
         FROM roles WHERE name = "SUPER_ADMIN"'
    )->execute(['user_id' => $authoritySuper['id']]);

    $authorityOperator = $auth->register([
        'first_name' => 'Authority',
        'last_name' => 'Operator',
        'email' => 'authority-operator-' . bin2hex(random_bytes(5)) . '@example.test',
        'password' => 'VeryStrongAuthorityOperator-123!',
    ]);

    $authorityAccess->assignScope(
        (string) $authoritySuper['id'],
        (string) $authorityOperator['id'],
        $authorityId,
        'AUTHORITY_ADMIN'
    );
    $authorityAccess->assignScope(
        (string) $authoritySuper['id'],
        (string) $authorityOperator['id'],
        $broadAuthorityId,
        'AUTHORITY_USER'
    );

    $operatorScopes = $authorityAccess->scopes((string) $authorityOperator['id']);
    $assert(
        count($operatorScopes) === 2
        && count(array_filter(
            $operatorScopes,
            static fn(array $scope): bool =>
                $scope['authority_id'] === $authorityId
                && $scope['scope_role'] === 'AUTHORITY_ADMIN'
        )) === 1
        && count(array_filter(
            $operatorScopes,
            static fn(array $scope): bool =>
                $scope['authority_id'] === $broadAuthorityId
                && $scope['scope_role'] === 'AUTHORITY_USER'
        )) === 1,
        'Authority user can hold different roles per authority scope'
    );

    $scopeEscalationBlocked = false;
    try {
        $authorityAccess->assertAuthority(
            (string) $authorityOperator['id'],
            $broadAuthorityId,
            'authority.holder.write'
        );
    } catch (AuthorizationException $e) {
        $scopeEscalationBlocked = true;
    }
    $assert(
        $scopeEscalationBlocked,
        'Global AUTHORITY_ADMIN role cannot escalate an AUTHORITY_USER scope'
    );

    $authorityInbox = $authorityPortal->inbox(
        (string) $authorityOperator['id'],
        $authorityId
    );
    $assert(
        count(array_filter(
            $authorityInbox,
            static fn(array $row): bool => ($row['id'] ?? null) === $caseId
        )) === 1,
        'Authority inbox contains sent case for matching authority scope'
    );

    $broadInbox = $authorityPortal->inbox(
        (string) $authorityOperator['id'],
        $broadAuthorityId
    );
    $assert(
        count(array_filter(
            $broadInbox,
            static fn(array $row): bool => ($row['id'] ?? null) === $caseId
        )) === 0,
        'Authority inbox excludes case not sent to that authority'
    );

    $authorityDetail = $authorityPortal->caseDetail(
        (string) $authorityOperator['id'],
        $caseId,
        $authorityId
    );
    $assert(
        ($authorityDetail['manifest']['case']['public_number'] ?? null)
            === ($afterDispatch['case']['public_number'] ?? null)
        && hash_equals(
            (string) $authorityDetail['dispatch']['package_sha256'],
            (string) $loadedDispatchPackage['manifest_sha256']
        ),
        'Authority case view uses frozen transmitted dispatch snapshot'
    );

    $crossAuthorityCaseBlocked = false;
    try {
        $authorityPortal->caseDetail(
            (string) $authorityOperator['id'],
            $caseId,
            $broadAuthorityId
        );
    } catch (AuthorizationException $e) {
        $crossAuthorityCaseBlocked = true;
    }
    $assert(
        $crossAuthorityCaseBlocked,
        'Exact authority binding blocks cross-authority case access'
    );

    $portalInquiry = $authorityPortal->createInquiry(
        (string) $authorityOperator['id'],
        $caseId,
        'PHOTO',
        'Weiteren Fotobeleg nachreichen',
        'Bitte reichen Sie einen zusätzlichen anonymisierten Fotobeleg nach.',
        new DateTimeImmutable('2026-10-01T12:00:00+02:00'),
        $authorityId
    );
    $assert(
        ($portalInquiry['status'] ?? null) === 'OPEN'
        && ($portalInquiry['inquiry_type'] ?? null) === 'PHOTO',
        'Authority portal creates structured inquiry'
    );

    $portalInquiryStorage = $pdo->prepare(
        'SELECT body_encrypted, body_sha256, case_task_id
         FROM authority_portal_inquiries WHERE id = :id LIMIT 1'
    );
    $portalInquiryStorage->execute(['id' => $portalInquiry['id']]);
    $portalInquiryRow = $portalInquiryStorage->fetch();
    $assert(
        is_array($portalInquiryRow)
        && !str_contains(
            (string) $portalInquiryRow['body_encrypted'],
            'zusätzlichen anonymisierten Fotobeleg'
        )
        && !empty($portalInquiryRow['case_task_id']),
        'Authority inquiry is encrypted and creates linked citizen task'
    );

    $portalTaskStmt = $pdo->prepare(
        'SELECT source, task_type, status
         FROM case_tasks WHERE id = :id LIMIT 1'
    );
    $portalTaskStmt->execute(['id' => $portalInquiry['case_task_id']]);
    $portalTask = $portalTaskStmt->fetch();
    $assert(
        is_array($portalTask)
        && $portalTask['source'] === 'AUTHORITY_PORTAL'
        && $portalTask['task_type'] === 'AUTHORITY_PHOTO'
        && $portalTask['status'] === 'OPEN',
        'Authority inquiry is visible in existing citizen task workflow'
    );

    $holderRecord = $authorityHolders->save(
        (string) $authorityOperator['id'],
        $caseId,
        'Max Mustermann',
        'Beispielweg 10, 35390 Gießen',
        '1980-01-02',
        ['source' => 'integration-test']
    );
    $assert(
        ($holderRecord['holder_name'] ?? null) === 'Max Mustermann'
        && ($holderRecord['date_of_birth'] ?? null) === '1980-01-02',
        'Authority admin can read own authority encrypted holder record'
    );

    $holderStorageStmt = $pdo->prepare(
        'SELECT holder_name_encrypted, holder_address_encrypted, payload_sha256
         FROM authority_holder_records
         WHERE authority_id = :authority_id AND case_id = :case_id LIMIT 1'
    );
    $holderStorageStmt->execute([
        'authority_id' => $authorityId,
        'case_id' => $caseId,
    ]);
    $holderStorage = $holderStorageStmt->fetch();
    $assert(
        is_array($holderStorage)
        && !str_contains((string) $holderStorage['holder_name_encrypted'], 'Max Mustermann')
        && !str_contains((string) $holderStorage['holder_address_encrypted'], 'Beispielweg 10')
        && strlen((string) $holderStorage['payload_sha256']) === 64,
        'Holder data is encrypted at rest and integrity hashed'
    );

    $holderScopeBlocked = false;
    try {
        $authorityAccess->assertAuthority(
            (string) $authorityOperator['id'],
            $broadAuthorityId,
            'authority.holder.read'
        );
    } catch (AuthorizationException $e) {
        $holderScopeBlocked = true;
    }
    $assert(
        $holderScopeBlocked,
        'AUTHORITY_USER scope cannot access protected holder data'
    );

    $authorityExport = $authorityExports->export(
        (string) $authorityOperator['id'],
        $authorityId,
        'json'
    );
    $assert(
        str_contains((string) $authorityExport['body'], (string) $afterDispatch['case']['public_number'])
        && !str_contains((string) $authorityExport['body'], 'Max Mustermann')
        && !str_contains((string) $authorityExport['body'], 'Beispielweg 10'),
        'Authority export includes scoped case but never isolated holder data'
    );
    $authorityXmlExport = $authorityExports->export(
        (string) $authorityOperator['id'],
        $authorityId,
        'xml'
    );
    $assert(
        str_contains((string) $authorityXmlExport['body'], '<meldeverkehr-export')
        && !str_contains((string) $authorityXmlExport['body'], 'Max Mustermann'),
        'Authority XML export is available without holder data'
    );

    $apiToken = $authorityTokens->create(
        (string) $authorityOperator['id'],
        $authorityId,
        'Integration API',
        ['cases:read','inquiries:write','exports:read'],
        new DateTimeImmutable('+7 days', new DateTimeZone('UTC'))
    );
    $assert(
        str_starts_with((string) $apiToken['token'], 'mvapi_'),
        'Authority API token plaintext is returned only at creation'
    );

    $apiTokenStorageStmt = $pdo->prepare(
        'SELECT token_hash, token_prefix, scopes_json
         FROM authority_api_tokens WHERE id = :id LIMIT 1'
    );
    $apiTokenStorageStmt->execute(['id' => $apiToken['id']]);
    $apiTokenStorage = $apiTokenStorageStmt->fetch();
    $assert(
        is_array($apiTokenStorage)
        && !str_contains((string) $apiTokenStorage['token_hash'], (string) $apiToken['token'])
        && hash_equals(
            (string) $apiTokenStorage['token_hash'],
            hash('sha256', (string) $apiToken['token'])
        ),
        'Authority API token is stored only as SHA-256 hash'
    );

    $apiContext = $authorityTokens->authenticate(
        'Bearer ' . $apiToken['token'],
        'cases:read'
    );
    $assert(
        ($apiContext['authority_id'] ?? null) === $authorityId
        && ($apiContext['user_id'] ?? null) === $authorityOperator['id'],
        'Authority API token authenticates exact authority and user scope'
    );

    $apiScopeBlocked = false;
    try {
        $authorityTokens->authenticate(
            'Bearer ' . $apiToken['token'],
            'holder:read'
        );
    } catch (AuthorizationException $e) {
        $apiScopeBlocked = true;
    }
    $assert(
        $apiScopeBlocked,
        'Authority API token cannot use scopes not granted at creation'
    );

    $broadApiToken = $authorityTokens->create(
        (string) $authoritySuper['id'],
        $broadAuthorityId,
        'Broad Authority API',
        ['cases:read'],
        new DateTimeImmutable('+7 days', new DateTimeZone('UTC'))
    );
    $broadApiContext = $authorityTokens->authenticate(
        'Bearer ' . $broadApiToken['token'],
        'cases:read'
    );

    $apiCrossAuthorityBlocked = false;
    try {
        $authorityPortal->caseDetail(
            (string) $broadApiContext['user_id'],
            $caseId,
            (string) $broadApiContext['authority_id']
        );
    } catch (AuthorizationException $e) {
        $apiCrossAuthorityBlocked = true;
    }
    $assert(
        $apiCrossAuthorityBlocked,
        'API token for another authority cannot read sent case'
    );

    $authorityTokens->revoke(
        (string) $authorityOperator['id'],
        $authorityId,
        (string) $apiToken['id']
    );
    $revokedTokenBlocked = false;
    try {
        $authorityTokens->authenticate(
            'Bearer ' . $apiToken['token'],
            'cases:read'
        );
    } catch (AuthorizationException $e) {
        $revokedTokenBlocked = true;
    }
    $assert(
        $revokedTokenBlocked,
        'Revoked authority API token is immediately rejected'
    );

    ReleaseRepository::ensure($pdo);

    $testBackupRoot = $basePath . '/storage/test-backups-' . bin2hex(random_bytes(4));
    $releaseCipher = new SecretCipher('test-app-key');
    $backupService = new BackupService(
        $pdo,
        $releaseCipher,
        $testBackupRoot,
        $basePath . '/storage/app',
        52428800
    );

    $databaseBackup = $backupService->create(null, false);
    $databaseBackupVerified = $backupService->verify((string) $databaseBackup['id']);
    $databaseEncryptedPath =
        $testBackupRoot . '/' . $databaseBackup['id'] . '/database.jsonl.enc';

    $assert(
        ($databaseBackup['status'] ?? null) === 'READY'
        && ($databaseBackupVerified['status'] ?? null) === 'VERIFIED'
        && (int) $databaseBackupVerified['runtime_files_verified'] === 0,
        'Encrypted database backup can be created and verified'
    );
    $assert(
        is_file($databaseEncryptedPath)
        && !str_contains(
            (string) file_get_contents($databaseEncryptedPath),
            $email
        ),
        'Database backup payload is encrypted at rest'
    );

    $runtimeMarkerPath = $basePath . '/storage/app/m12-runtime-marker.txt';
    $runtimeMarker = 'M12-RUNTIME-' . bin2hex(random_bytes(8));
    file_put_contents($runtimeMarkerPath, $runtimeMarker, LOCK_EX);

    $fullBackup = $backupService->create(null, true);
    $fullBackupVerified = $backupService->verify((string) $fullBackup['id']);
    $fullManifestPath =
        $testBackupRoot . '/' . $fullBackup['id'] . '/manifest.json';
    $fullManifest = json_decode(
        (string) file_get_contents($fullManifestPath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $markerManifest = null;

    foreach ($fullManifest['runtime']['files'] ?? [] as $file) {
        if (($file['path'] ?? null) === 'm12-runtime-marker.txt') {
            $markerManifest = $file;
            break;
        }
    }

    $assert(
        ($fullBackupVerified['status'] ?? null) === 'VERIFIED'
        && (int) $fullBackupVerified['runtime_files_verified'] >= 1
        && is_array($markerManifest),
        'Full backup includes and verifies runtime files'
    );

    $markerEncryptedBody = is_array($markerManifest)
        ? (string) file_get_contents(
            $testBackupRoot . '/' . $fullBackup['id'] . '/' .
            $markerManifest['encrypted_path']
        )
        : '';

    $assert(
        $markerEncryptedBody !== ''
        && !str_contains($markerEncryptedBody, $runtimeMarker),
        'Runtime backup file is encrypted at rest'
    );

    $restoreConfirmationBlocked = false;
    try {
        $backupService->restore((string) $fullBackup['id'], false);
    } catch (InvalidArgumentException $e) {
        $restoreConfirmationBlocked = true;
    }
    $assert(
        $restoreConfirmationBlocked,
        'Restore cannot run without explicit confirmation'
    );

    @unlink($runtimeMarkerPath);

    $releaseRateLimiter = new RequestRateLimiter($pdo, 'test-release-rate-key');
    $rateSubject = 'integration-' . bin2hex(random_bytes(5));
    $rate1 = $releaseRateLimiter->consume('TEST_BUCKET', $rateSubject, 2, 60, 60);
    $rate2 = $releaseRateLimiter->consume('TEST_BUCKET', $rateSubject, 2, 60, 60);
    $rate3 = $releaseRateLimiter->consume('TEST_BUCKET', $rateSubject, 2, 60, 60);

    $assert(
        $rate1['allowed'] === true
        && $rate2['allowed'] === true
        && $rate3['allowed'] === false
        && (int) $rate3['retry_after'] > 0,
        'Generic request limiter blocks burst after configured allowance'
    );

    $maintenancePath = $testBackupRoot . '/maintenance.flag';
    $maintenanceService = new MaintenanceService($maintenancePath);
    $maintenanceService->enable('Integrationstest');
    $maintenanceStatus = $maintenanceService->status();
    $assert(
        $maintenanceStatus['active'] === true
        && $maintenanceStatus['reason'] === 'Integrationstest',
        'Maintenance mode persists reason and active state'
    );
    $maintenanceService->disable();
    $assert(
        $maintenanceService->active() === false,
        'Maintenance mode can be disabled cleanly'
    );

    $updateService = new UpdateService(
        $pdo,
        $backupService,
        $maintenanceService,
        $runner,
        new AuditLogger($pdo, 'test-audit-key'),
        $testBackupRoot . '/last-version.txt'
    );
    $updatePreflight = $updateService->preflight('0.11.0-dev', '0.12.0-rc1');
    $assert(
        ($updatePreflight['from_version'] ?? null) === '0.11.0-dev'
        && ($updatePreflight['to_version'] ?? null) === '0.12.0-rc1'
        && ($updatePreflight['pending_migrations'] ?? ['unexpected']) === [],
        'Update preflight reports versions and no pending migrations after test migration run'
    );

    $releaseApp = new Application(
        new Router(),
        $config,
        $basePath,
        false
    );
    $readiness = (new ReleaseReadinessService(
        $releaseApp,
        new MaintenanceService($testBackupRoot . '/readiness-maintenance.flag')
    ))->check();
    $readinessKeys = array_column($readiness['checks'], 'key');
    $assert(
        in_array('production_debug', $readinessKeys, true)
        && in_array('install_lock', $readinessKeys, true)
        && in_array('pwa', $readinessKeys, true)
        && array_key_exists('blocking_failures', $readiness),
        'Release readiness reports production debug install lock and PWA checks'
    );

    $pwaScript = (string) file_get_contents($basePath . '/public/assets/app.js');
    $caseViewSource = (string) file_get_contents($basePath . '/resources/views/cases/show.php');
    $securityHeaderSource = (string) file_get_contents($basePath . '/app/Release/SecurityHeaders.php');
    $rootHtaccess = (string) file_get_contents($basePath . '/.htaccess');
    $publicEntry = (string) file_get_contents($basePath . '/public/index.php');

    $assert(
        str_contains($pwaScript, 'indexedDB')
        && str_contains($pwaScript, 'serverVersion')
        && str_contains($pwaScript, 'Nichts wurde automatisch überschrieben')
        && str_contains($caseViewSource, 'data-offline-draft="1"'),
        'PWA offline drafts require explicit conflict resolution and never silently overwrite'
    );
    $assert(
        str_contains($pwaScript, 'enhanceAccessibility')
        && str_contains($pwaScript, 'dataset.skipLink')
        && str_contains($pwaScript, ':focus-visible')
        && str_contains($pwaScript, "setAttribute('role', 'alert')")
        && str_contains($pwaScript, "setAttribute('aria-live', 'polite')"),
        'Accessibility helper provides skip link focus visibility alerts and live regions'
    );
    $assert(
        str_contains($securityHeaderSource, 'Content-Security-Policy')
        && str_contains($securityHeaderSource, 'Strict-Transport-Security')
        && str_contains($rootHtaccess, 'maintenance\\.php')
        && str_contains($publicEntry, '503'),
        'Production entrypoint contains CSP HSTS maintenance gate and CLI web denial'
    );

    $releaseRoutesSource = (string) file_get_contents($basePath . '/routes/web.php');
    $releasePackageWorkflow = (string) file_get_contents(
        $basePath . '/.github/workflows/release-package.yml'
    );
    $assert(
        str_contains($releaseRoutesSource, "'/health/live'")
        && str_contains($releaseRoutesSource, "'/health/ready'")
        && str_contains($publicEntry, "'/health/live'")
        && str_contains($publicEntry, "'/health/ready'"),
        'Liveness and readiness probes remain reachable during maintenance'
    );
    $assert(
        str_contains($releasePackageWorkflow, "--exclude='.env'")
        && str_contains($releasePackageWorkflow, "--exclude='storage/app/*'")
        && str_contains($releasePackageWorkflow, 'sha256sum')
        && str_contains($releasePackageWorkflow, 'actions/upload-artifact@v4'),
        'Release packaging excludes runtime secrets and emits SHA-256 artifact'
    );

    for ($batchIndex = 0; $batchIndex < 260; $batchIndex++) {
        $notificationService->create(
            (string) $user['id'],
            null,
            'M12_BATCH_BOUNDARY',
            'm12-batch:' . $user['id'] . ':' . $batchIndex,
            'LOW',
            'M12 Batch ' . $batchIndex,
            null,
            '/dashboard'
        );
    }
    $batchNotificationRows = $notificationService->list(
        (string) $user['id'],
        false,
        1000
    );
    $assert(
        count($batchNotificationRows) === 250,
        'Notification center clamps oversized batch request to 250'
    );
    $assert(
        count($caseSearch->search((string) $user['id'], [], 1000)['results']) <= 250,
        'Global case search clamps oversized batch request to 250'
    );
    $assert(
        count($authorityPortal->inbox(
            (string) $authorityOperator['id'],
            $authorityId,
            null,
            null,
            1000
        )) <= 250,
        'Authority inbox clamps oversized batch request to 250'
    );

    $warningCase = $caseService->createDraft((string) $user['id']);
    $warningCaseId = (string) $warningCase['case']['id'];
    $caseService->saveVehicle((string) $user['id'], $warningCaseId, [
        'license_plate' => 'GI-CD 456',
        'vehicle_type' => 'PKW',
    ]);
    $caseService->saveLocation((string) $user['id'], $warningCaseId, [
        'street' => 'Warnstraße',
        'city' => 'Gießen',
        'traffic_space_type' => 'UNKNOWN',
        'access_type' => 'UNCLEAR',
    ]);
    $caseService->saveObservation((string) $user['id'], $warningCaseId, [
        'observed_from' => '2026-09-24T11:00',
        'observed_until' => null,
    ]);
    $caseService->setPrimaryOffense((string) $user['id'], $warningCaseId, $newerVersionId);
    $warningReview = $caseService->reviewSummary((string) $user['id'], $warningCaseId);
    $assert(count($warningReview['warnings']) >= 2, 'Review exposes quality warnings');

    $warningBlocked = false;
    try {
        $caseService->confirmCoreReview((string) $user['id'], $warningCaseId, false);
    } catch (DomainException $e) {
        $warningBlocked = true;
    }
    $assert($warningBlocked, 'Review warnings require explicit acknowledgement');

    $caseService->confirmCoreReview((string) $user['id'], $warningCaseId, true);
    $warningAfterReview = $caseService->findOwned((string) $user['id'], $warningCaseId);
    $assert(
        ($warningAfterReview['case']['status'] ?? null) === CaseStatus::WAITING_FOR_EVIDENCE,
        'Acknowledged warnings allow transition to evidence'
    );

    $lifecycleCase = $caseService->createDraft((string) $user['id']);
    $lifecycleCaseId = (string) $lifecycleCase['case']['id'];

    $caseService->saveVehicle((string) $user['id'], $lifecycleCaseId, [
        'license_plate' => 'GI-LC 130',
        'vehicle_type' => 'PKW',
    ]);

    $caseLifecycle = new CaseLifecycleService(
        $pdo,
        new AuthorizationService($permissions),
        new AuditLogger($pdo, 'test-audit-key')
    );

    $lifecycleInitial = $caseLifecycle->overview((string) $user['id'], $lifecycleCaseId);
    $assert(
        count($lifecycleInitial['versions']) >= 2
        && count(array_filter(
            $lifecycleInitial['versions'],
            static fn(array $row): bool => ($row['integrity_valid'] ?? false) !== true
        )) === 0,
        'Case lifecycle automatically versions baseline and core changes with valid integrity hashes'
    );

    $amendment = $caseLifecycle->addAmendment(
        (string) $user['id'],
        $lifecycleCaseId,
        'Nachtrag zum Standort',
        'Zusätzliche Beobachtung wurde nachträglich dokumentiert.'
    );
    $assert(
        (int) $amendment['amendment_no'] === 1
        && (int) $amendment['version']['version_no'] >= 3,
        'Case amendment is append-only and creates a new immutable version'
    );

    $pdo->prepare(
        'UPDATE cases SET status = "SENT", updated_at = UTC_TIMESTAMP() WHERE id = :id'
    )->execute(['id' => $lifecycleCaseId]);

    $correction = $caseLifecycle->requestCorrection(
        (string) $user['id'],
        $lifecycleCaseId,
        'VEHICLE',
        'GI-LC 130',
        'GI-LC 131',
        'Kennzeichen wurde bei der Erstmeldung fehlerhaft übertragen.'
    );
    $afterCorrectionRequest = $caseLifecycle->overview((string) $user['id'], $lifecycleCaseId);
    $assert(
        ($afterCorrectionRequest['case']['status'] ?? null) === CaseStatus::CORRECTION_PENDING
        && ($afterCorrectionRequest['corrections'][0]['status'] ?? null) === 'OPEN',
        'Correction workflow freezes pre-correction state and enters CORRECTION_PENDING'
    );

    $completedCorrectionVersion = $caseLifecycle->completeCorrection(
        (string) $user['id'],
        $lifecycleCaseId,
        (string) $correction['id'],
        'Korrektur wurde dokumentiert und an die weitere Bearbeitung übergeben.'
    );
    $afterCorrectionComplete = $caseLifecycle->overview((string) $user['id'], $lifecycleCaseId);
    $assert(
        ($afterCorrectionComplete['case']['status'] ?? null) === CaseStatus::AUTHORITY_PROCESSING
        && ($afterCorrectionComplete['corrections'][0]['status'] ?? null) === 'COMPLETED'
        && (int) $completedCorrectionVersion['version_no'] > (int) $correction['before_version']['version_no'],
        'Correction completion preserves history and returns case to authority processing'
    );

    $withdrawal = $caseLifecycle->requestWithdrawal(
        (string) $user['id'],
        $lifecycleCaseId,
        'Der Vorgang soll nach Rücksprache zurückgenommen werden.'
    );
    $afterWithdrawalRequest = $caseLifecycle->overview((string) $user['id'], $lifecycleCaseId);
    $assert(
        ($afterWithdrawalRequest['case']['status'] ?? null) === CaseStatus::WITHDRAWAL_PENDING
        && ($afterWithdrawalRequest['withdrawals'][0]['status'] ?? null) === 'OPEN',
        'Withdrawal workflow freezes current state and enters WITHDRAWAL_PENDING'
    );

    $closure = $caseLifecycle->completeWithdrawal(
        (string) $user['id'],
        $lifecycleCaseId,
        (string) $withdrawal['id'],
        'Rücknahme als erledigt dokumentiert.'
    );
    $afterWithdrawalComplete = $caseLifecycle->overview((string) $user['id'], $lifecycleCaseId);
    $assert(
        ($afterWithdrawalComplete['case']['status'] ?? null) === CaseStatus::CLOSED
        && (int) ($afterWithdrawalComplete['closures'][0]['closure_no'] ?? 0) === 1
        && ($afterWithdrawalComplete['closures'][0]['integrity_valid'] ?? false) === true,
        'Withdrawal completion closes case and generates integrity-protected closure dossier'
    );

    $closureExport = $caseLifecycle->closureDossier(
        (string) $user['id'],
        $lifecycleCaseId,
        (string) $closure['id']
    );
    $assert(
        str_contains((string) $closureExport['filename'], 'Abschlussakte_01.json')
        && hash_equals(
            (string) $closureExport['closure']['dossier_sha256'],
            hash('sha256', (string) $closureExport['json'])
        ),
        'Closure dossier export verifies SHA-256 before download'
    );

    $closureDocuments = $documentCenter->list((string) $user['id'], 'CLOSURE_DOSSIER');
    $assert(
        count(array_filter(
            $closureDocuments,
            static fn(array $row): bool => ($row['case_id'] ?? null) === $lifecycleCaseId
        )) === 1,
        'Document center exposes generated closure dossier'
    );

    $foreignLifecycleBlocked = false;
    try {
        $caseLifecycle->overview((string) $other['id'], $lifecycleCaseId);
    } catch (AuthorizationException $e) {
        $foreignLifecycleBlocked = true;
    }
    $assert($foreignLifecycleBlocked, 'Case lifecycle enforces owner isolation');

    $caseLifecycle->archiveCase((string) $user['id'], $lifecycleCaseId);
    $afterArchive = $caseLifecycle->overview((string) $user['id'], $lifecycleCaseId);
    $assert(
        ($afterArchive['case']['status'] ?? null) === CaseStatus::ARCHIVED
        && ($afterArchive['closures'][0]['archived_at'] ?? null) !== null,
        'Closed case can be archived with its own immutable archive version'
    );

    $summary = $caseService->dashboardSummary((string) $user['id']);
    $assert($summary['open'] >= 2, 'Dashboard counts own open cases');
    $assert(count($summary['recent']) >= 2, 'Dashboard returns recent cases');

    $authorization = new AuthorizationService($permissions);
    $assert(
        $authorization->can((string) $user['id'], 'case.view_own', (string) $user['id']),
        'Owner can access own resource'
    );
    $assert(
        !$authorization->can((string) $user['id'], 'case.view_own', Uuid::v4()),
        'Owner permission does not grant foreign resource access'
    );

    $cipher = new SecretCipher('test-app-key');
    $encrypted = $cipher->encrypt('sensitive-secret');
    $assert($encrypted !== 'sensitive-secret', 'Secret encryption changes plaintext');
    $assert($cipher->decrypt($encrypted) === 'sensitive-secret', 'Secret encryption roundtrip');

    $assert(
        Totp::verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59, 0),
        'TOTP RFC 6238 vector'
    );

    $roundtrip = WebAuthnService::b64url('webauthn-test');
    $assert(WebAuthnService::fromB64url($roundtrip) === 'webauthn-test', 'WebAuthn Base64URL roundtrip');

    $decodedCbor = (new CborDecoder())->decode(hex2bin('a201020326') ?: '');
    $assert(
        is_array($decodedCbor) && ($decodedCbor['1'] ?? null) === 2 && ($decodedCbor['3'] ?? null) === -7,
        'WebAuthn CBOR decoder handles COSE integer map'
    );

    $limiter = new LoginRateLimiter($pdo, 'test-key', 3, 15, 15);
    $limitKey = $limiter->keyFor($email, '127.0.0.1');
    $limiter->clear($limitKey);
    $limiter->hit($limitKey);
    $limiter->hit($limitKey);
    $assert(!$limiter->blocked($limitKey), 'Rate limit not blocked before threshold');
    $limiter->hit($limitKey);
    $assert($limiter->blocked($limitKey), 'Rate limit blocks at threshold');

    $audit = new AuditLogger($pdo, 'test-audit-key');
    $audit->log('TEST_EVENT', 'user', (string) $user['id'], 'USER', (string) $user['id'], ['test' => true]);
    $integrity = $audit->verifyChain();
    $assert($integrity['ok'] === true, 'Audit chain integrity');

    $queue = new JobQueue($pdo);
    $jobUuid = $queue->push('TEST_JOB', ['value' => 42], 10, 2);
    $job = $queue->claim('test-worker');
    $assert(is_array($job) && $job['uuid'] === $jobUuid, 'Queue claim');
    $assert(($job['payload']['value'] ?? null) === 42, 'Queue payload decoding');
    $queue->complete((int) $job['id']);
    $stmt = $pdo->prepare('SELECT status FROM jobs WHERE uuid = :uuid');
    $stmt->execute(['uuid' => $jobUuid]);
    $assert($stmt->fetchColumn() === 'DONE', 'Queue completion');

    $pdo->prepare('DELETE FROM auth_rate_limits WHERE key_hash = :key')->execute(['key' => $limitKey]);
} catch (Throwable $e) {
    $failed++;
    echo '[ERROR] ' . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . sprintf('Passed: %d  Failed: %d', $passed, $failed) . PHP_EOL;
exit($failed > 0 ? 1 : 0);
