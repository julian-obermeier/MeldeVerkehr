<?php

declare(strict_types=1);

namespace MeldeVerkehr\Dispatch;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Cases\CaseStatus;
use MeldeVerkehr\Communication\ReplyAddressService;
use MeldeVerkehr\Evidence\EvidenceStorage;
use MeldeVerkehr\Queue\JobQueue;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class DispatchService
{
    public const JOB_TYPE = 'DISPATCH_SEND';

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization,
        private readonly CaseService $cases,
        private readonly AuthorityRoutingService $routing,
        private readonly DispatchPackageService $packages,
        private readonly JobQueue $queue,
        private readonly DispatchTransportInterface $transport,
        private readonly ReplyAddressService $replyAddresses,
        private readonly EvidenceStorage $storage,
        private readonly AuditLogger $audit
    ) {
    }

    public function review(string $userId, string $caseId): array
    {
        $caseData = $this->cases->findOwned($userId, $caseId);

        if ($caseData === null) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        $this->authorization->authorize(
            $userId,
            'case.submit',
            (string) $caseData['case']['user_id']
        );

        if ((string) $caseData['case']['status'] !== CaseStatus::READY_FOR_SUBMISSION) {
            throw new \DomainException('Vorgang ist noch nicht versandbereit.');
        }

        $route = $this->routing->routeForCase($userId, $caseId);
        $errors = [];
        $warnings = [];

        if ($route['status'] === 'NONE') {
            $errors[] = 'Für diesen Vorgang wurde noch keine passende Behörde gefunden.';
        } elseif ($route['status'] === 'AMBIGUOUS') {
            $errors[] = 'Die Zuständigkeit ist mehrdeutig und muss administrativ geklärt werden.';
        }

        $selected = $route['selected'];

        if (!is_array($selected)) {
            return [
                'case_data' => $caseData,
                'route' => $route,
                'requirements' => null,
                'package_preview' => null,
                'errors' => $errors,
                'warnings' => $warnings,
                'ready' => false,
            ];
        }

        if ($selected['channel'] !== 'EMAIL') {
            $errors[] = 'Der gefundene Versandkanal wird in dieser M5-Ausbaustufe noch nicht automatisch unterstützt.';
        }

        if (
            $selected['channel'] === 'EMAIL'
            && !filter_var((string) $selected['endpoint_value'], FILTER_VALIDATE_EMAIL)
        ) {
            $errors[] = 'Der hinterlegte E-Mail-Endpunkt ist ungültig.';
        }

        if ($route['requires_confirmation']) {
            $warnings[] = 'Die Route ist nicht als exakt verifiziert markiert und muss ausdrücklich bestätigt werden.';
        }

        $requirements = $this->routing->requirements((string) $selected['authority_id']);
        $packagePreview = $this->packages->preview(
            $userId,
            $caseId,
            $selected,
            $requirements
        );

        $errors = array_merge($errors, $packagePreview['errors']);
        $warnings = array_merge($warnings, $packagePreview['warnings']);

        return [
            'case_data' => $caseData,
            'route' => $route,
            'requirements' => $requirements,
            'package_preview' => $packagePreview,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'ready' => $errors === [],
        ];
    }

    public function queueDispatch(
        string $userId,
        string $caseId,
        bool $routeConfirmed,
        bool $warningsAcknowledged
    ): array {
        $review = $this->review($userId, $caseId);

        if (!$review['ready']) {
            throw new \DomainException('Versand kann wegen blockierender Punkte noch nicht gestartet werden.');
        }

        if ($review['route']['requires_confirmation'] && !$routeConfirmed) {
            throw new \DomainException('Die vorgeschlagene Zuständigkeit muss ausdrücklich bestätigt werden.');
        }

        if ($review['warnings'] !== [] && !$warningsAcknowledged) {
            throw new \DomainException('Versandhinweise müssen ausdrücklich bestätigt werden.');
        }

        $selected = $review['route']['selected'];
        $package = $this->packages->freeze(
            $userId,
            $caseId,
            $selected,
            $review['requirements']
        );

        $dispatchId = Uuid::v4();
        $replyAddress = $this->replyAddresses->create($caseId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO dispatches
             (id, case_id, dispatch_package_id, authority_id, endpoint_id, reply_address_id, channel, status,
              queue_job_uuid, outbound_message_id, requested_by_user_id, created_at, queued_at, sent_at, completed_at, last_error)
             VALUES
             (:id, :case_id, :package_id, :authority_id, :endpoint_id, :reply_address_id, :channel, "CREATED",
              NULL, NULL, :user_id, UTC_TIMESTAMP(), NULL, NULL, NULL, NULL)'
        );
        $stmt->execute([
            'id' => $dispatchId,
            'case_id' => $caseId,
            'package_id' => $package['id'],
            'authority_id' => $selected['authority_id'],
            'endpoint_id' => $selected['endpoint_id'],
            'reply_address_id' => $replyAddress['id'],
            'channel' => $selected['channel'],
            'user_id' => $userId,
        ]);

        $this->replyAddresses->attachToDispatch((string) $replyAddress['id'], $dispatchId);

        $jobUuid = $this->queue->push(
            self::JOB_TYPE,
            ['dispatch_id' => $dispatchId],
            100,
            5
        );

        $update = $this->pdo->prepare(
            'UPDATE dispatches
             SET status = "QUEUED", queue_job_uuid = :job_uuid, queued_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $update->execute(['job_uuid' => $jobUuid, 'id' => $dispatchId]);

        try {
            $this->cases->changeStatus(
                $userId,
                $caseId,
                CaseStatus::SUBMISSION_PENDING,
                'Versandauftrag in Queue eingestellt'
            );
        } catch (Throwable $e) {
            $this->pdo->prepare(
                'UPDATE dispatches SET status = "CANCELLED", last_error = :error WHERE id = :id'
            )->execute([
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'id' => $dispatchId,
            ]);
            throw $e;
        }

        $this->audit->log('DISPATCH_QUEUED', 'case', $caseId, 'USER', $userId, [
            'dispatch_id' => $dispatchId,
            'package_id' => $package['id'],
            'authority_id' => $selected['authority_id'],
            'endpoint_id' => $selected['endpoint_id'],
            'job_uuid' => $jobUuid,
        ]);

        return [
            'dispatch_id' => $dispatchId,
            'package_id' => $package['id'],
            'job_uuid' => $jobUuid,
            'status' => DispatchStatus::QUEUED,
            'reply_address' => $replyAddress['address'],
        ];
    }

    public function process(string $dispatchId): void
    {
        $dispatch = $this->loadDispatch($dispatchId);

        if ($dispatch['status'] === DispatchStatus::CANCELLED || $dispatch['status'] === DispatchStatus::SENT) {
            return;
        }

        if (!in_array($dispatch['status'], [
            DispatchStatus::QUEUED,
            DispatchStatus::DELIVERY_FAILED,
            DispatchStatus::SENDING,
        ], true)) {
            throw new \DomainException('Dispatch befindet sich nicht in einem verarbeitbaren Status.');
        }

        if ($dispatch['status'] === DispatchStatus::DELIVERY_FAILED) {
            $this->cases->changeStatus(
                (string) $dispatch['requested_by_user_id'],
                (string) $dispatch['case_id'],
                CaseStatus::SUBMISSION_PENDING,
                'Automatischer Versandwiederholungsversuch'
            );
        }

        $attemptNo = $this->nextAttempt($dispatchId);

        $this->pdo->prepare(
            'UPDATE dispatches SET status = "SENDING", last_error = NULL WHERE id = :id'
        )->execute(['id' => $dispatchId]);

        $this->pdo->prepare(
            'INSERT INTO dispatch_attempts
             (dispatch_id, attempt_no, status, provider_reference, response_json, started_at, completed_at)
             VALUES (:dispatch_id, :attempt_no, "SENDING", NULL, NULL, UTC_TIMESTAMP(), NULL)'
        )->execute(['dispatch_id' => $dispatchId, 'attempt_no' => $attemptNo]);

        try {
            $package = $this->packages->load((string) $dispatch['dispatch_package_id']);
            $manifest = $package['manifest'];
            $message = $this->message($manifest);
            $attachments = $this->attachments($manifest);

            $replyAddress = $this->replyAddressForDispatch($dispatchId);
            if ($replyAddress === null) {
                throw new \RuntimeException('Reply-Adresse für Dispatch fehlt.');
            }

            $messageId = '<mv-' . str_replace('-', '', $dispatchId) . '@' . $this->addressDomain((string) $replyAddress['full_address']) . '>';

            $result = $this->transport->send(
                $dispatchId,
                (string) $manifest['authority']['endpoint_value'],
                $message['subject'],
                $message['text'],
                $attachments,
                [
                    'Reply-To' => (string) $replyAddress['full_address'],
                    'Message-ID' => $messageId,
                ]
            );

            if (!$result['accepted']) {
                throw new \RuntimeException(
                    'Transport hat den Versand nicht angenommen: '
                    . json_encode($result['response'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            $this->pdo->prepare(
                'UPDATE dispatch_attempts
                 SET status = "SENT", provider_reference = :reference, response_json = :response,
                     completed_at = UTC_TIMESTAMP()
                 WHERE dispatch_id = :dispatch_id AND attempt_no = :attempt_no'
            )->execute([
                'reference' => $result['provider_reference'],
                'response' => json_encode(
                    $result['response'],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
                'dispatch_id' => $dispatchId,
                'attempt_no' => $attemptNo,
            ]);

            $this->pdo->prepare(
                'UPDATE dispatches
                 SET status = "SENT", outbound_message_id = :message_id,
                     sent_at = UTC_TIMESTAMP(), completed_at = UTC_TIMESTAMP(), last_error = NULL
                 WHERE id = :id'
            )->execute([
                'message_id' => $result['message_id'] ?? $messageId,
                'id' => $dispatchId,
            ]);

            $this->cases->changeStatus(
                (string) $dispatch['requested_by_user_id'],
                (string) $dispatch['case_id'],
                CaseStatus::SENT,
                'Versandtransport hat die Nachricht angenommen'
            );

            $this->audit->log('DISPATCH_SENT', 'case', (string) $dispatch['case_id'], 'SYSTEM', null, [
                'dispatch_id' => $dispatchId,
                'attempt_no' => $attemptNo,
                'provider_reference' => $result['provider_reference'],
            ]);
        } catch (Throwable $e) {
            $this->pdo->prepare(
                'UPDATE dispatch_attempts
                 SET status = "FAILED", response_json = :response, completed_at = UTC_TIMESTAMP()
                 WHERE dispatch_id = :dispatch_id AND attempt_no = :attempt_no'
            )->execute([
                'response' => json_encode(
                    ['error' => mb_substr($e->getMessage(), 0, 1500)],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
                'dispatch_id' => $dispatchId,
                'attempt_no' => $attemptNo,
            ]);

            $this->pdo->prepare(
                'UPDATE dispatches
                 SET status = "DELIVERY_FAILED", last_error = :error
                 WHERE id = :id'
            )->execute([
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'id' => $dispatchId,
            ]);

            $currentCase = $this->cases->findOwned(
                (string) $dispatch['requested_by_user_id'],
                (string) $dispatch['case_id']
            );

            if (
                $currentCase !== null
                && (string) $currentCase['case']['status'] === CaseStatus::SUBMISSION_PENDING
            ) {
                $this->cases->changeStatus(
                    (string) $dispatch['requested_by_user_id'],
                    (string) $dispatch['case_id'],
                    CaseStatus::DELIVERY_FAILED,
                    'Versandversuch fehlgeschlagen'
                );
            }

            throw $e;
        }
    }

    public function latestForCase(string $userId, string $caseId): ?array
    {
        $case = $this->cases->findOwned($userId, $caseId);
        if ($case === null) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT d.*, a.name AS authority_name, ep.endpoint_value
             FROM dispatches d
             INNER JOIN authorities a ON a.id = d.authority_id
             INNER JOIN authority_endpoints ep ON ep.id = d.endpoint_id
             WHERE d.case_id = :case_id
             ORDER BY d.created_at DESC LIMIT 1'
        );
        $stmt->execute(['case_id' => $caseId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function attachments(array $manifest): array
    {
        $attachments = [];
        $evidencePackageId = (string) $manifest['evidence_package']['id'];

        $stmt = $this->pdo->prepare(
            'SELECT epi.order_no, epi.category_snapshot, epi.evidence_id, epi.variant, epi.version_no,
                    epi.sha256_snapshot, ev.storage_path, ev.mime_type, ev.file_size
             FROM evidence_package_items epi
             INNER JOIN evidence_versions ev
               ON ev.evidence_id = epi.evidence_id
              AND ev.variant = epi.variant
              AND ev.version_no = epi.version_no
             WHERE epi.package_id = :package_id
             ORDER BY epi.order_no'
        );
        $stmt->execute(['package_id' => $evidencePackageId]);

        foreach ($stmt->fetchAll() as $row) {
            $absolute = $this->storage->absolute((string) $row['storage_path']);

            if (!is_file($absolute)) {
                throw new \RuntimeException('Versandanlage fehlt im geschützten Storage.');
            }

            $hash = hash_file('sha256', $absolute);
            if (!hash_equals((string) $row['sha256_snapshot'], $hash)) {
                throw new \RuntimeException('Integrität einer Versandanlage ist verletzt.');
            }

            $extension = match ((string) $row['mime_type']) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'bin',
            };

            $attachments[] = [
                'name' => sprintf(
                    '%02d_%s.%s',
                    (int) $row['order_no'],
                    preg_replace('/[^A-Z0-9_-]/', '_', (string) $row['category_snapshot']) ?: 'EVIDENCE',
                    $extension
                ),
                'mime_type' => (string) $row['mime_type'],
                'path' => $absolute,
                'sha256' => $hash,
                'size' => (int) $row['file_size'],
            ];
        }

        return $attachments;
    }

    private function message(array $manifest): array
    {
        $snapshot = $manifest['witness_report']['snapshot'];
        $narrative = (string) ($snapshot['narrative']['text'] ?? '');
        $reporter = $manifest['reporter'];
        $number = (string) $manifest['case']['public_number'];

        $text = "MeldeVerkehr – strukturierte Meldung\n\n"
            . 'Vorgang: ' . $number . "\n"
            . 'Meldende Person: ' . $reporter['first_name'] . ' ' . $reporter['last_name'] . "\n"
            . 'E-Mail: ' . $reporter['email'] . "\n\n"
            . "Sachverhalt:\n" . $narrative . "\n\n"
            . 'Beweisanlagen: ' . count($manifest['evidence_package']['items']) . "\n"
            . 'Evidence-Manifest SHA-256: ' . $manifest['evidence_package']['manifest_sha256'] . "\n"
            . 'Zeugenbericht SHA-256: ' . $manifest['witness_report']['snapshot_sha256'] . "\n\n"
            . 'Die abschließende fachliche und rechtliche Würdigung liegt bei der zuständigen Stelle.';

        return [
            'subject' => 'Verkehrsordnungswidrigkeit – ' . $number,
            'text' => $text,
        ];
    }

    private function loadDispatch(string $dispatchId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM dispatches WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $dispatchId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new \DomainException('Dispatch nicht gefunden.');
        }

        return $row;
    }

    private function replyAddressForDispatch(string $dispatchId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ra.id, ra.full_address
             FROM dispatches d
             INNER JOIN case_reply_addresses ra ON ra.id = d.reply_address_id
             WHERE d.id = :id AND ra.status = "ACTIVE"
             LIMIT 1'
        );
        $stmt->execute(['id' => $dispatchId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function addressDomain(string $address): string
    {
        $parts = explode('@', $address, 2);
        $domain = strtolower(trim($parts[1] ?? ''));

        return preg_match('/^[a-z0-9.-]+$/', $domain) ? $domain : 'reply.invalid';
    }

    private function nextAttempt(string $dispatchId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(attempt_no), 0) + 1
             FROM dispatch_attempts WHERE dispatch_id = :dispatch_id'
        );
        $stmt->execute(['dispatch_id' => $dispatchId]);

        return max(1, (int) $stmt->fetchColumn());
    }
}
