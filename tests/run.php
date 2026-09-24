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
use MeldeVerkehr\Communication\AuthorityMessageClassifier;
use MeldeVerkehr\Communication\AuthorityReplyJobHandler;
use MeldeVerkehr\Communication\AuthorityReplyService;
use MeldeVerkehr\Communication\CommunicationService;
use MeldeVerkehr\Communication\CommunicationStorage;
use MeldeVerkehr\Communication\ReplyAddressService;
use MeldeVerkehr\Config\Config;
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
use MeldeVerkehr\Queue\JobQueue;
use MeldeVerkehr\Queue\JobWorker;
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
        && ($ingested['classification'] ?? null) === 'DEADLINE'
        && ($ingested['deadline_at'] ?? null) === '2026-09-30 21:59:59',
        'Inbound authority mail is routed classified and deadline-extracted'
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
            static fn(array $task): bool => $task['status'] === 'OPEN' && $task['task_type'] === 'DEADLINE'
        )) === 1
        && count(array_filter(
            $communication['deadlines'],
            static fn(array $deadline): bool => $deadline['status'] === 'OPEN'
        )) === 1,
        'Inbound deadline creates one open task and one open deadline'
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
