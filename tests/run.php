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
use MeldeVerkehr\Queue\JobQueue;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Env;
use MeldeVerkehr\Support\Uuid;

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

    $offenseId = Uuid::v4();
    $offenseVersionId = Uuid::v4();
    $pdo->prepare(
        'INSERT INTO offenses (id, stable_key, category_key, active, created_at, updated_at)
         VALUES (:id, :stable_key, "OTHER", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute([
        'id' => $offenseId,
        'stable_key' => 'TEST_CONCRETE_OFFENSE_' . bin2hex(random_bytes(4)),
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

    $caseService->setPrimaryOffense((string) $user['id'], $caseId, $offenseVersionId);
    $afterOffense = $caseService->findOwned((string) $user['id'], $caseId);
    $assert(
        ($afterOffense['case']['status'] ?? null) === CaseStatus::WAITING_FOR_EVIDENCE,
        'Complete M2 core advances to WAITING_FOR_EVIDENCE'
    );

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
