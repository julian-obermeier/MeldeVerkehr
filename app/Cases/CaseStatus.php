<?php

declare(strict_types=1);

namespace MeldeVerkehr\Cases;

final class CaseStatus
{
    public const DRAFT = 'DRAFT';
    public const CAPTURE_IN_PROGRESS = 'CAPTURE_IN_PROGRESS';
    public const WAITING_FOR_EVIDENCE = 'WAITING_FOR_EVIDENCE';
    public const READY_FOR_REVIEW = 'READY_FOR_REVIEW';
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    public const READY_FOR_SUBMISSION = 'READY_FOR_SUBMISSION';
    public const SUBMISSION_PENDING = 'SUBMISSION_PENDING';
    public const SENT = 'SENT';
    public const DELIVERED = 'DELIVERED';
    public const DELIVERY_UNKNOWN = 'DELIVERY_UNKNOWN';
    public const DELIVERY_FAILED = 'DELIVERY_FAILED';
    public const AUTHORITY_REPLY = 'AUTHORITY_REPLY';
    public const USER_ACTION_REQUIRED = 'USER_ACTION_REQUIRED';
    public const AUTHORITY_PROCESSING = 'AUTHORITY_PROCESSING';
    public const CORRECTION_PENDING = 'CORRECTION_PENDING';
    public const WITHDRAWAL_PENDING = 'WITHDRAWAL_PENDING';
    public const CLOSED = 'CLOSED';
    public const ARCHIVED = 'ARCHIVED';
    public const DELETION_PENDING = 'DELETION_PENDING';

    public static function all(): array
    {
        return [
            self::DRAFT,
            self::CAPTURE_IN_PROGRESS,
            self::WAITING_FOR_EVIDENCE,
            self::READY_FOR_REVIEW,
            self::REVIEW_REQUIRED,
            self::READY_FOR_SUBMISSION,
            self::SUBMISSION_PENDING,
            self::SENT,
            self::DELIVERED,
            self::DELIVERY_UNKNOWN,
            self::DELIVERY_FAILED,
            self::AUTHORITY_REPLY,
            self::USER_ACTION_REQUIRED,
            self::AUTHORITY_PROCESSING,
            self::CORRECTION_PENDING,
            self::WITHDRAWAL_PENDING,
            self::CLOSED,
            self::ARCHIVED,
            self::DELETION_PENDING,
        ];
    }
}
