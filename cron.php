<?php

declare(strict_types=1);

use MeldeVerkehr\Cron\CronHeartbeat;
use MeldeVerkehr\Cron\CronRegistry;
use MeldeVerkehr\Communication\AuthorityReplyJobHandler;
use MeldeVerkehr\Communication\CommunicationServiceFactory;
use MeldeVerkehr\Communication\InboundMailPoller;
use MeldeVerkehr\Dispatch\DispatchJobHandler;
use MeldeVerkehr\Dispatch\DispatchServiceFactory;
use MeldeVerkehr\Queue\JobQueue;
use MeldeVerkehr\Queue\JobWorker;

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
