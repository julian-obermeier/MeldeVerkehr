<?php

declare(strict_types=1);

namespace MeldeVerkehr\Queue;

use Throwable;

final class JobWorker
{
    /** @var array<string, JobHandlerInterface> */
    private array $handlers = [];

    public function __construct(
        private readonly JobQueue $queue,
        array $handlers = []
    ) {
        foreach ($handlers as $handler) {
            if ($handler instanceof JobHandlerInterface) {
                $this->handlers[$handler->type()] = $handler;
            }
        }
    }

    public function run(string $workerId, int $limit = 20): array
    {
        $processed = 0;
        $errors = 0;

        for ($i = 0; $i < max(1, $limit); $i++) {
            $job = $this->queue->claim($workerId);

            if ($job === null) {
                break;
            }

            $processed++;
            $type = (string) $job['type'];
            $handler = $this->handlers[$type] ?? null;

            if ($handler === null) {
                $errors++;
                $this->queue->fail($job, new \RuntimeException('No handler registered for job type: ' . $type));
                continue;
            }

            try {
                $handler->handle(is_array($job['payload']) ? $job['payload'] : []);
                $this->queue->complete((int) $job['id']);
            } catch (Throwable $e) {
                $errors++;
                $this->queue->fail($job, $e);
            }
        }

        return [
            'processed' => $processed,
            'errors' => $errors,
        ];
    }
}
