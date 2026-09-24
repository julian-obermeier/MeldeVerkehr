<?php

declare(strict_types=1);

namespace MeldeVerkehr\Evidence;

use MeldeVerkehr\Assist\ImageQualityAnalyzer;
use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Cases\CaseStatus;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class EvidenceService
{
    private const MAX_FILE_SIZE = 20 * 1024 * 1024;
    private const ALLOWED_MIME = ['image/jpeg','image/png','image/webp'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization,
        private readonly EvidenceStorage $storage,
        private readonly AuditLogger $audit,
        private readonly ?EvidenceImageProcessor $processor = null,
        private readonly ?ImageQualityAnalyzer $qualityAnalyzer = null
    ) {
    }

    public function storeFile(
        string $userId,
        string $caseId,
        string $sourcePath,
        string $originalName,
        string $category,
        string $source = 'UPLOAD',
        ?string $capturedAt = null
    ): array {
        $case = $this->ownedCase($userId, $caseId, 'evidence.upload');

        if ($case['status'] !== CaseStatus::WAITING_FOR_EVIDENCE) {
            throw new \DomainException('Beweise können erst nach bestätigtem Grunddaten-Review hinzugefügt werden.');
        }

        $category = strtoupper(trim($category));
        if (!in_array($category, EvidenceCategory::all(), true)) {
            throw new \InvalidArgumentException('Ungültige Beweiskategorie.');
        }

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \InvalidArgumentException('Upload-Datei ist nicht lesbar.');
        }

        $size = filesize($sourcePath);
        if ($size === false || $size < 1 || $size > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('Dateigröße ist ungültig oder überschreitet 20 MB.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($sourcePath);

        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new \InvalidArgumentException('Nur JPEG, PNG und WebP sind als Bildnachweis zulässig.');
        }

        $dimensions = @getimagesize($sourcePath);
        if (!is_array($dimensions) || !isset($dimensions[0], $dimensions[1])) {
            throw new \InvalidArgumentException('Bilddatei ist technisch nicht lesbar.');
        }

        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        $quality = $this->qualityState($width, $height, $size);
        $evidenceId = Uuid::v4();
        $stored = $this->storage->storeOriginal($caseId, $evidenceId, $sourcePath, $mime);

        try {
            $working = $this->processor?->createWorkingCopy(
                $caseId,
                $evidenceId,
                (string) $stored['relative_path'],
                $mime
            );
        } catch (Throwable $e) {
            $this->storage->deletePhysical((string) $stored['relative_path']);
            throw $e;
        }

        $qualityMetrics = null;
        $qualityVariant = is_array($working) ? 'WORKING' : 'ORIGINAL';
        $qualityRelativePath = is_array($working)
            ? (string) $working['relative_path']
            : (string) $stored['relative_path'];

        try {
            $qualityMetrics = $this->qualityAnalyzer?->analyze(
                $this->storage->absolute($qualityRelativePath),
                $mime
            );
        } catch (Throwable) {
            $qualityMetrics = null;
        }

        if (is_array($qualityMetrics)) {
            $quality = (string) $qualityMetrics['overall_state'];
        }

        try {
            $this->pdo->beginTransaction();

            $item = $this->pdo->prepare(
                'INSERT INTO evidence_items
                 (id, case_id, category, source, original_filename, captured_at, status, quality_state,
                  created_by_user_id, created_at, updated_at)
                 VALUES
                 (:id, :case_id, :category, :source, :filename, :captured_at, "ACTIVE", :quality,
                  :user_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $item->execute([
                'id' => $evidenceId,
                'case_id' => $caseId,
                'category' => $category,
                'source' => strtoupper(trim($source)) ?: 'UPLOAD',
                'filename' => mb_substr(basename($originalName), 0, 255),
                'captured_at' => $capturedAt,
                'quality' => $quality,
                'user_id' => $userId,
            ]);

            $version = $this->pdo->prepare(
                'INSERT INTO evidence_versions
                 (id, evidence_id, variant, version_no, storage_path, mime_type, file_size, sha256,
                  width, height, processing_json, created_at)
                 VALUES
                 (:id, :evidence_id, "ORIGINAL", 1, :path, :mime, :size, :sha256,
                  :width, :height, NULL, UTC_TIMESTAMP())'
            );
            $version->execute([
                'id' => Uuid::v4(),
                'evidence_id' => $evidenceId,
                'path' => $stored['relative_path'],
                'mime' => $mime,
                'size' => $stored['size'],
                'sha256' => $stored['sha256'],
                'width' => $width,
                'height' => $height,
            ]);

            if (is_array($working)) {
                $workingVersion = $this->pdo->prepare(
                    'INSERT INTO evidence_versions
                     (id, evidence_id, variant, version_no, storage_path, mime_type, file_size, sha256,
                      width, height, processing_json, created_at)
                     VALUES
                     (:id, :evidence_id, "WORKING", 1, :path, :mime, :size, :sha256,
                      :width, :height, :processing, UTC_TIMESTAMP())'
                );
                $workingVersion->execute([
                    'id' => Uuid::v4(),
                    'evidence_id' => $evidenceId,
                    'path' => $working['relative_path'],
                    'mime' => $working['mime_type'],
                    'size' => $working['size'],
                    'sha256' => $working['sha256'],
                    'width' => $working['width'],
                    'height' => $working['height'],
                    'processing' => json_encode(
                        $working['processing'],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    ),
                ]);

                $this->event($evidenceId, 'WORKING_COPY_CREATED', $userId, [
                    'sha256' => $working['sha256'],
                    'width' => $working['width'],
                    'height' => $working['height'],
                ]);
            }

            if (is_array($qualityMetrics)) {
                $qualityStmt = $this->pdo->prepare(
                    'INSERT INTO evidence_quality_metrics
                     (id, evidence_id, version_no, source_variant, width, height,
                      brightness_mean, contrast_stddev, sharpness_score,
                      resolution_state, brightness_state, contrast_state, sharpness_state,
                      overall_state, metrics_json, created_at)
                     VALUES
                     (:id, :evidence_id, 1, :source_variant, :width, :height,
                      :brightness_mean, :contrast_stddev, :sharpness_score,
                      :resolution_state, :brightness_state, :contrast_state, :sharpness_state,
                      :overall_state, :metrics_json, UTC_TIMESTAMP())'
                );
                $qualityStmt->execute([
                    'id' => Uuid::v4(),
                    'evidence_id' => $evidenceId,
                    'source_variant' => $qualityVariant,
                    'width' => $qualityMetrics['width'],
                    'height' => $qualityMetrics['height'],
                    'brightness_mean' => $qualityMetrics['brightness_mean'],
                    'contrast_stddev' => $qualityMetrics['contrast_stddev'],
                    'sharpness_score' => $qualityMetrics['sharpness_score'],
                    'resolution_state' => $qualityMetrics['resolution_state'],
                    'brightness_state' => $qualityMetrics['brightness_state'],
                    'contrast_state' => $qualityMetrics['contrast_state'],
                    'sharpness_state' => $qualityMetrics['sharpness_state'],
                    'overall_state' => $qualityMetrics['overall_state'],
                    'metrics_json' => json_encode(
                        $qualityMetrics,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    ),
                ]);

                $this->event($evidenceId, 'QUALITY_METRICS_ANALYZED', $userId, [
                    'source_variant' => $qualityVariant,
                    'overall_state' => $qualityMetrics['overall_state'],
                    'algorithm' => $qualityMetrics['algorithm'],
                    'warnings' => $qualityMetrics['warnings'],
                ]);
            }

            $this->event($evidenceId, 'ORIGINAL_STORED', $userId, [
                'category' => $category,
                'quality_state' => $quality,
                'sha256' => $stored['sha256'],
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->storage->deletePhysical($stored['relative_path']);
            if (is_array($working)) {
                $this->storage->deletePhysical((string) $working['relative_path']);
            }
            throw $e;
        }

        $this->audit->log('EVIDENCE_ORIGINAL_STORED', 'evidence', $evidenceId, 'USER', $userId, [
            'case_id' => $caseId,
            'category' => $category,
            'quality_state' => $quality,
            'sha256' => $stored['sha256'],
        ]);

        return $this->findOwned($userId, $evidenceId)
            ?? throw new \RuntimeException('Gespeicherter Nachweis konnte nicht geladen werden.');
    }

    public function listForCase(string $userId, string $caseId): array
    {
        $this->ownedCase($userId, $caseId, 'case.view_own');

        $stmt = $this->pdo->prepare(
            'SELECT e.id, e.category, e.source, e.original_filename, e.captured_at, e.status,
                    e.quality_state, e.created_at,
                    v.mime_type, v.file_size, v.sha256, v.width, v.height,
                    EXISTS(
                        SELECT 1 FROM evidence_versions w
                        WHERE w.evidence_id = e.id AND w.variant = "WORKING"
                    ) AS has_working_copy,
                    EXISTS(
                        SELECT 1 FROM evidence_privacy_reviews pr
                        WHERE pr.evidence_id = e.id
                    ) AS privacy_reviewed
             FROM evidence_items e
             INNER JOIN evidence_versions v
               ON v.evidence_id = e.id AND v.variant = "ORIGINAL" AND v.version_no = 1
             WHERE e.case_id = :case_id
             ORDER BY e.created_at DESC'
        );
        $stmt->execute(['case_id' => $caseId]);

        return $stmt->fetchAll();
    }

    public function findOwned(string $userId, string $evidenceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*, c.user_id AS owner_user_id,
                    v.storage_path, v.mime_type, v.file_size, v.sha256, v.width, v.height
             FROM evidence_items e
             INNER JOIN cases c ON c.id = e.case_id
             INNER JOIN evidence_versions v
               ON v.evidence_id = e.id AND v.variant = "ORIGINAL" AND v.version_no = 1
             WHERE e.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $evidenceId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $this->authorization->authorize($userId, 'case.view_own', (string) $row['owner_user_id']);
        unset($row['storage_path'], $row['owner_user_id']);

        return $row;
    }

    public function markRemoved(string $userId, string $evidenceId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id, e.case_id, e.status, c.user_id, c.status AS case_status
             FROM evidence_items e
             INNER JOIN cases c ON c.id = e.case_id
             WHERE e.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $evidenceId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new \DomainException('Nachweis nicht gefunden.');
        }

        $this->authorization->authorize($userId, 'evidence.upload', (string) $row['user_id']);

        if ($row['case_status'] !== CaseStatus::WAITING_FOR_EVIDENCE) {
            throw new \DomainException('Nachweis kann in diesem Vorgangsstatus nicht entfernt werden.');
        }

        $update = $this->pdo->prepare(
            'UPDATE evidence_items SET status = "REMOVED", updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $update->execute(['id' => $evidenceId]);

        $this->event($evidenceId, 'REMOVED', $userId);
        $this->audit->log('EVIDENCE_REMOVED', 'evidence', $evidenceId, 'USER', $userId, [
            'case_id' => $row['case_id'],
        ]);
    }

    public function verifyIntegrity(string $userId, string $evidenceId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id, c.user_id, v.storage_path, v.sha256
             FROM evidence_items e
             INNER JOIN cases c ON c.id = e.case_id
             INNER JOIN evidence_versions v
               ON v.evidence_id = e.id AND v.variant = "ORIGINAL" AND v.version_no = 1
             WHERE e.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $evidenceId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new \DomainException('Nachweis nicht gefunden.');
        }

        $this->authorization->authorize($userId, 'evidence.view_original', (string) $row['user_id']);

        return hash_equals((string) $row['sha256'], $this->storage->hash((string) $row['storage_path']));
    }

    private function ownedCase(string $userId, string $caseId, string $permission): array
    {
        $stmt = $this->pdo->prepare('SELECT id, user_id, status FROM cases WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $caseId]);
        $case = $stmt->fetch();

        if (!is_array($case)) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        $this->authorization->authorize($userId, $permission, (string) $case['user_id']);

        return $case;
    }

    private function qualityState(int $width, int $height, int $size): string
    {
        $long = max($width, $height);
        $short = min($width, $height);

        if ($long >= 1600 && $short >= 900 && $size >= 120000) {
            return 'SUITABLE';
        }

        if ($long >= 1000 && $short >= 600 && $size >= 60000) {
            return 'LIMITED';
        }

        return 'RETAKE_RECOMMENDED';
    }

    private function event(string $evidenceId, string $type, ?string $userId, array $payload = []): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO evidence_events
             (evidence_id, event_type, actor_user_id, payload_json, created_at)
             VALUES (:evidence_id, :event_type, :actor_user_id, :payload, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'evidence_id' => $evidenceId,
            'event_type' => $type,
            'actor_user_id' => $userId,
            'payload' => $payload === [] ? null : json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
        ]);
    }
}
