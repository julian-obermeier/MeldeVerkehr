<?php

declare(strict_types=1);

namespace MeldeVerkehr\Communication;

use MeldeVerkehr\Queue\JobHandlerInterface;

final class AuthorityReplyJobHandler implements JobHandlerInterface
{
    public function __construct(private readonly AuthorityReplyService $replies)
    {
    }

    public function type(): string
    {
        return AuthorityReplyService::JOB_TYPE;
    }

    public function handle(array $payload): void
    {
        $draftId = trim((string) ($payload['draft_id'] ?? ''));

        if ($draftId === '') {
            throw new \InvalidArgumentException('Authority reply job requires draft_id.');
        }

        $this->replies->process($draftId);
    }
}
