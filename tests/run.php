<?php

declare(strict_types=1);

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Auth\AuthService;
use MeldeVerkehr\Auth\LoginRateLimiter;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Auth\Totp;
use MeldeVerkehr\Auth\WebAuthn\CborDecoder;
use MeldeVerkehr\Auth\WebAuthn\WebAuthnService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Cases\CaseStatus;
use MeldeVerkehr\Cases\CaseStatusMachine;
use MeldeVerkehr\Config\Config;
use MeldeVerkehr\Database\Connection;
use MeldeVerkehr\Database\MigrationRunner;
use MeldeVerkehr\Evidence\EvidenceImageProcessor;
use MeldeVerkehr\Evidence\EvidencePrivacyService;
use MeldeVerkehr\Evidence\EvidenceReviewService;
use MeldeVerkehr\Evidence\EvidenceService;
use MeldeVerkehr\Evidence\EvidenceStorage;
use MeldeVerkehr\Queue\JobQueue;
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
