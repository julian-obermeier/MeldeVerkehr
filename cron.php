<?php

declare(strict_types=1);

use MeldeVerkehr\Cron\CronHeartbeat;
use MeldeVerkehr\Cron\CronRegistry;
use MeldeVerkehr\Communication\AuthorityReplyJobHandler;
use MeldeVerkehr\Communication\CommunicationServiceFactory;
use MeldeVerkehr\Communication\InboundMailPoller;
use MeldeVerkehr\Dispatch\DispatchJobHandler;
use MeldeVerkehr\Dispatch\DispatchServiceFactory;
use MeldeVerkehr\Operations\OperationsServiceFactory;
use MeldeVerkehr\Queue\JobQueue;
use MeldeVerkehr\Queue\JobWorker;
use MeldeVerkehr\Release\ReleaseServiceFactory;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require __DIR__ . '/bootstrap/app.php';

$task = $argv[1] ?? '';
$heartbeat = new CronHeartbeat($app->database());
$registry = new CronRegistry();

$registry->register('queue', static function () use ($app): array {
    $workerId = gethostname() . ':' . getmypid();
    $worker = new JobWorker(
        new JobQueue($app->database()),
        [
            new DispatchJobHandler(DispatchServiceFactory::make($app)),
            new AuthorityReplyJobHandler(
                CommunicationServiceFactory::services($app)['replies']
            ),
        ]
    );

    return $worker->run($workerId, 20);
});

$registry->register('inbound-mail', static function () use ($app): array {
    if (!(bool) $app->config()->get('communications.inbound_enabled', false)) {
        return [
            'processed' => 0,
            'errors' => 0,
            'message' => 'Inbound mail is disabled.',
        ];
    }

    $services = CommunicationServiceFactory::services($app);
    $poller = new InboundMailPoller(
        CommunicationServiceFactory::inboundSource($app),
        $services['communications']
    );

    return $poller->run(20);
});

$registry->register('notifications', static function () use ($app): array {
    $stmt = $app->database()->query(
        'SELECT id FROM users WHERE status = "ACTIVE" AND email_verified_at IS NOT NULL ORDER BY id'
    );

    $service = OperationsServiceFactory::notifications($app);
    $processed = 0;
    $created = 0;

    foreach ($stmt->fetchAll() as $row) {
        $created += $service->syncForUser((string) $row['id']);
        $processed++;
    }

    return [
        'processed' => $processed,
        'errors' => 0,
        'message' => 'Created notifications: ' . $created,
    ];
});

$registry->register('export-cleanup', static function () use ($app): array {
    $count = OperationsServiceFactory::exports($app)->cleanupExpired();

    return [
        'processed' => $count,
        'errors' => 0,
        'message' => 'Expired exports cleaned.',
    ];
});

$registry->register('retention-plan', static function () use ($app): array {
    $stmt = $app->database()->query(
        'SELECT id FROM users WHERE status = "ACTIVE" ORDER BY id'
    );
    $service = OperationsServiceFactory::retention($app);
    $processed = 0;
    $planned = 0;

    foreach ($stmt->fetchAll() as $row) {
        $result = $service->planForUser((string) $row['id']);
        $planned += (int) $result['planned'];
        $processed++;
    }

    return [
        'processed' => $processed,
        'errors' => 0,
        'message' => 'Retention schedules created: ' . $planned
            . '; automatic case deletion remains disabled.',
    ];
});

$registry->register('rate-limit-cleanup', static function () use ($app): array {
    $count = ReleaseServiceFactory::rateLimiter($app)->cleanup();

    return [
        'processed' => $count,
        'errors' => 0,
        'message' => 'Expired request rate-limit buckets cleaned.',
    ];
});

$registry->register('health', static function (): array {
    return [
        'processed' => 1,
        'errors' => 0,
        'message' => 'Cron subsystem is healthy.',
    ];
});

if ($task === '' || !$registry->has($task)) {
    fwrite(STDERR, 'Usage: php cron.php <' . implode('|', $registry->names()) . '>' . PHP_EOL);
    exit(2);
}

$runUuid = $heartbeat->start($task);

try {
    $result = $registry->run($task);
    $status = $result['errors'] > 0 ? 'WARNING' : 'SUCCESS';

    $heartbeat->finish(
        $runUuid,
        $status,
        $result['processed'],
        $result['errors'],
        $result['message']
    );

    echo sprintf(
        "%s: processed=%d errors=%d%s",
        $task,
        $result['processed'],
        $result['errors'],
        PHP_EOL
    );

    exit($result['errors'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    $heartbeat->finish($runUuid, 'FAILED', 0, 1, mb_substr($e->getMessage(), 0, 2000));
    fwrite(STDERR, 'Cron failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
