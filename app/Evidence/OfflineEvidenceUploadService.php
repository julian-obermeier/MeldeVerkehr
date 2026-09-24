<?php

declare(strict_types=1);

namespace MeldeVerkehr\Evidence;

use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class OfflineEvidenceUploadService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly EvidenceService $evidence
    ) {
    }

    public function context(string $userId, string $caseId): array
    {
        // Reuse the evidence ownership/permission guard before exposing queue state.
        $this->evidence->listForCase($userId, $caseId);

        $stmt = $this->pdo->prepare(
            'SELECT id, public_number, status
             FROM cases
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $caseId, 'user_id' => $userId]);
        $case = $stmt->fetch();

        if (!is_array($case)) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        return [
            'case_id' => (string) $case['id'],
            'public_number' => (string) $case['public_number'],
            'status' => (string) $case['status'],
            'can_upload' => (string) $case['status'] === \MeldeVerkehr\Cases\CaseStatus::WAITING_FOR_EVIDENCE,
            'max_file_bytes' => 20 * 1024 * 1024,
            'allowed_mime' => ['image/jpeg','image/png','image/webp'],
        ];
    }

    public function process(
        string $userId,
        string $caseId,
        string $clientUploadId,
        string $clientSha256,
        string $sourcePath,
        string $originalName,
        string $category
    ): array {
        $clientUploadId = strtolower(trim($clientUploadId));
        $clientSha256 = strtolower(trim($clientSha256));

        if (!$this->validUuid($clientUploadId)) {
            throw new \InvalidArgumentException('Lokale Upload-ID ist ungültig.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $clientSha256)) {
            throw new \InvalidArgumentException('Lokaler SHA-256-Wert ist ungültig.');
        }
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \InvalidArgumentException('Upload-Datei ist nicht lesbar.');
        }

        $serverHash = hash_file('sha256', $sourcePath);
        if (!is_string($serverHash) || !hash_equals($clientSha256, strtolower($serverHash))) {
            throw new \InvalidArgumentException('Lokale Datei und Server-Upload stimmen nicht überein.');
        }

        $existing = $this->receipt($clientUploadId);
        if ($existing !== null) {
            if (
                (string) $existing['user_id'] !== $userId
                || (string) $existing['case_id'] !== $caseId
                || !hash_equals((string) $existing['client_sha256'], $clientSha256)
            ) {
                throw new \DomainException('Diese lokale Upload-ID ist bereits anderweitig vergeben.');
            }

            $recovered = $this->evidence->findOwned(
                $userId,
                (string) $existing['reserved_evidence_id']
            );

            if (is_array($recovered)) {
                if (!hash_equals($clientSha256, strtolower((string) $recovered['sha256']))) {
                    throw new \RuntimeException('Idempotenzbeleg verweist auf einen abweichenden Nachweis.');
                }

                $this->markDone($clientUploadId);
                return [
                    'duplicate' => true,
                    'receipt_status' => 'DONE',
                    'evidence' => $recovered,
                ];
            }

            if ((string) $existing['status'] === 'PROCESSING') {
                $updated = strtotime((string) $existing['updated_at'] . ' UTC');
                if ($updated !== false && $updated > time() - 120) {
                    throw new \DomainException('Dieser Offline-Upload wird bereits verarbeitet.');
                }
            }

            $this->pdo->prepare(
                'UPDATE offline_evidence_uploads
                 SET status = "PROCESSING", last_error = NULL, updated_at = UTC_TIMESTAMP()
                 WHERE client_upload_id = :id'
            )->execute(['id' => $clientUploadId]);
            $reservedEvidenceId = (string) $existing['reserved_evidence_id'];
        } else {
            $reservedEvidenceId = Uuid::v4();

            $this->pdo->prepare(
                'INSERT INTO offline_evidence_uploads
                 (client_upload_id, user_id, case_id, reserved_evidence_id, client_sha256,
                  status, original_filename, category, created_at, updated_at)
                 VALUES
                 (:client_upload_id, :user_id, :case_id, :evidence_id, :sha256,
                  "PROCESSING", :filename, :category, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            )->execute([
                'client_upload_id' => $clientUploadId,
                'user_id' => $userId,
                'case_id' => $caseId,
                'evidence_id' => $reservedEvidenceId,
                'sha256' => $clientSha256,
                'filename' => mb_substr(basename($originalName), 0, 255),
                'category' => mb_substr(strtoupper(trim($category)), 0, 40),
            ]);
        }

        try {
            $stored = $this->evidence->storeFile(
                $userId,
                $caseId,
                $sourcePath,
                $originalName,
                $category,
                'OFFLINE_QUEUE',
                null,
                $reservedEvidenceId
            );

            if (!hash_equals($clientSha256, strtolower((string) $stored['sha256']))) {
                throw new \RuntimeException('Gespeicherter Nachweis stimmt nicht mit dem lokalen SHA-256 überein.');
            }

            $this->markDone($clientUploadId);

            return [
                'duplicate' => false,
                'receipt_status' => 'DONE',
                'evidence' => $stored,
            ];
        } catch (Throwable $e) {
            $this->pdo->prepare(
                'UPDATE offline_evidence_uploads
                 SET status = "FAILED", last_error = :error, updated_at = UTC_TIMESTAMP()
                 WHERE client_upload_id = :id'
            )->execute([
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'id' => $clientUploadId,
            ]);
            throw $e;
        }
    }

    public function receiptForUser(string $userId, string $clientUploadId): ?array
    {
        $row = $this->receipt(strtolower(trim($clientUploadId)));
        if ($row === null || (string) $row['user_id'] !== $userId) {
            return null;
        }

        unset($row['last_error']);
        return $row;
    }

    private function receipt(string $clientUploadId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT client_upload_id, user_id, case_id, reserved_evidence_id, client_sha256,
                    status, last_error, created_at, updated_at, completed_at
             FROM offline_evidence_uploads
             WHERE client_upload_id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $clientUploadId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function markDone(string $clientUploadId): void
    {
        $this->pdo->prepare(
            'UPDATE offline_evidence_uploads
             SET status = "DONE", last_error = NULL,
                 completed_at = COALESCE(completed_at, UTC_TIMESTAMP()),
                 updated_at = UTC_TIMESTAMP()
             WHERE client_upload_id = :id'
        )->execute(['id' => $clientUploadId]);
    }

    private function validUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value
        );
    }
}
