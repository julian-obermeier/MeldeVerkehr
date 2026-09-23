<?php

declare(strict_types=1);

namespace MeldeVerkehr\Queue;

interface JobHandlerInterface
{
    public function type(): string;

    public function handle(array $payload): void;
}
