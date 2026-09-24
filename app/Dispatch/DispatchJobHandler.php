<?php

declare(strict_types=1);

namespace MeldeVerkehr\Dispatch;

use MeldeVerkehr\Queue\JobHandlerInterface;

final class DispatchJobHandler implements JobHandlerInterface
{
    public function __construct(private readonly DispatchService $dispatch)
    {
    }

    public function type(): string
    {
        return DispatchService::JOB_TYPE;
    }

    public function handle(array $payload): void
    {
        $dispatchId = trim((string) ($payload['dispatch_id'] ?? ''));

        if ($dispatchId === '') {
            throw new \InvalidArgumentException('Dispatch job requires dispatch_id.');
        }

        $this->dispatch->process($dispatchId);
    }
}
