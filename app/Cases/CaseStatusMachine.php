<?php

declare(strict_types=1);

namespace MeldeVerkehr\Cases;

final class CaseStatusMachine
{
    private const TRANSITIONS = [
        CaseStatus::DRAFT => [
            CaseStatus::CAPTURE_IN_PROGRESS,
            CaseStatus::DELETION_PENDING,
        ],
        CaseStatus::CAPTURE_IN_PROGRESS => [
            CaseStatus::DRAFT,
            CaseStatus::READY_FOR_REVIEW,
            CaseStatus::DELETION_PENDING,
        ],
        CaseStatus::WAITING_FOR_EVIDENCE => [
            CaseStatus::CAPTURE_IN_PROGRESS,
            CaseStatus::READY_FOR_REVIEW,
            CaseStatus::DELETION_PENDING,
        ],
        CaseStatus::READY_FOR_REVIEW => [
            CaseStatus::CAPTURE_IN_PROGRESS,
            CaseStatus::WAITING_FOR_EVIDENCE,
            CaseStatus::REVIEW_REQUIRED,
            CaseStatus::READY_FOR_SUBMISSION,
        ],
        CaseStatus::REVIEW_REQUIRED => [
            CaseStatus::CAPTURE_IN_PROGRESS,
            CaseStatus::READY_FOR_REVIEW,
        ],
        CaseStatus::READY_FOR_SUBMISSION => [
            CaseStatus::CAPTURE_IN_PROGRESS,
            CaseStatus::SUBMISSION_PENDING,
        ],
        CaseStatus::SUBMISSION_PENDING => [
            CaseStatus::SENT,
            CaseStatus::DELIVERY_FAILED,
        ],
        CaseStatus::SENT => [
            CaseStatus::DELIVERED,
            CaseStatus::DELIVERY_UNKNOWN,
            CaseStatus::DELIVERY_FAILED,
            CaseStatus::AUTHORITY_REPLY,
            CaseStatus::AUTHORITY_PROCESSING,
            CaseStatus::WITHDRAWAL_PENDING,
        ],
        CaseStatus::DELIVERED => [
            CaseStatus::AUTHORITY_PROCESSING,
            CaseStatus::AUTHORITY_REPLY,
            CaseStatus::USER_ACTION_REQUIRED,
            CaseStatus::WITHDRAWAL_PENDING,
            CaseStatus::CLOSED,
        ],
        CaseStatus::DELIVERY_UNKNOWN => [
            CaseStatus::DELIVERED,
            CaseStatus::DELIVERY_FAILED,
            CaseStatus::AUTHORITY_REPLY,
        ],
        CaseStatus::DELIVERY_FAILED => [
            CaseStatus::SUBMISSION_PENDING,
            CaseStatus::WITHDRAWAL_PENDING,
        ],
        CaseStatus::AUTHORITY_REPLY => [
            CaseStatus::USER_ACTION_REQUIRED,
            CaseStatus::AUTHORITY_PROCESSING,
            CaseStatus::CLOSED,
        ],
        CaseStatus::USER_ACTION_REQUIRED => [
            CaseStatus::AUTHORITY_PROCESSING,
            CaseStatus::CORRECTION_PENDING,
            CaseStatus::WITHDRAWAL_PENDING,
            CaseStatus::CLOSED,
        ],
        CaseStatus::AUTHORITY_PROCESSING => [
            CaseStatus::AUTHORITY_REPLY,
            CaseStatus::USER_ACTION_REQUIRED,
            CaseStatus::CLOSED,
        ],
        CaseStatus::CORRECTION_PENDING => [
            CaseStatus::AUTHORITY_PROCESSING,
            CaseStatus::USER_ACTION_REQUIRED,
        ],
        CaseStatus::WITHDRAWAL_PENDING => [
            CaseStatus::CLOSED,
        ],
        CaseStatus::CLOSED => [
            CaseStatus::ARCHIVED,
        ],
        CaseStatus::ARCHIVED => [],
        CaseStatus::DELETION_PENDING => [],
    ];

    public function can(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function assert(string $from, string $to): void
    {
        if (!$this->can($from, $to)) {
            throw new \DomainException(sprintf('Status transition %s -> %s is not allowed.', $from, $to));
        }
    }
}
