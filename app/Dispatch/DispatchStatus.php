<?php

declare(strict_types=1);

namespace MeldeVerkehr\Dispatch;

final class DispatchStatus
{
    public const CREATED = 'CREATED';
    public const QUEUED = 'QUEUED';
    public const SENDING = 'SENDING';
    public const SENT = 'SENT';
    public const DELIVERY_FAILED = 'DELIVERY_FAILED';
    public const CANCELLED = 'CANCELLED';
}
