<?php

declare(strict_types=1);

namespace MeldeVerkehr\Evidence;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Cases\CaseStatus;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class EvidencePrivacyService
{
    private const REGION_TYPES = ['FACE','FOREIGN_PLATE','PERSON','SENSITIVE','OTHER'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization,
        private readonly EvidenceStorage $storage,
        private readonly EvidenceImageProcessor $processor,
        private readonly AuditLogger $audit
    ) {
    }

    public static function regionTypes(): array
    {
        return self::REGION_TYPES;
    }

    public function detail(string $userId, string $evidenceId): array
    {
        $evidence = $this->ownedEvidence($userId, $evidenceId, 'case.view_own');

        $regions = $this->regions($evidenceId);
        $review = $this->fetchOne(
            'SELECT public_version_no, regions_sha256, reviewed_at
             FROM evidence_privacy_reviews WHERE evidence_id = :id LIMIT 1',
            ['id' => $evidenceId]
        );

        return [
            'evidence' => $evidence,
            'regions' => $regions,
            'review' => $review,
        ];
    }

    public function addRegion(
        string $userId,
        string $evidenceId,
        string $type,
        float $x,
        float $y,
        float $width,
        float $height
    ): string {
        $evidence = $this->ownedEvidence($userId, $evidenceId, 'evidence.upload');
        $this->assertEditableStatus((string) $evidence['case_status']);

        $type = strtoupper(trim($type));
        if (!in_array($type, self::REGION_TYPES, true)) {
            throw new \InvalidArgumentException('Ungültiger Privacy-Bereichstyp.');
        }

        $this->assertRegion($x, $y, $width, $height);
        $id = Uuid::v4();

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(
                'INSERT INTO evidence_privacy_regions
                 (id, evidence_id, region_type, x, y, width, height, source, status,
                  created_by_user_id, created_at, updated_at)
                 VALUES
                 (:id, :evidence_id, :region_type, :x, :y, :width, :height, "MANUAL", "CONFIRMED",
                  :user_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $stmt->execute([
                'id' => $id,
                'evidence_id' => $evidenceId,
                'region_type' => $type,
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'height' => $height,
                'user_id' => $userId,
            ]);

            $this->invalidateReview($evidenceId);
            $this->event($evidenceId, 'PRIVACY_REGION_ADDED', $userId, [
                'region_id' => $id,
                'region_type' => $type,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log('EVIDENCE_PRIVACY_REGION_ADDED', 'evidence', $evidenceId, 'USER', $userId, [
            'region_id' => $id,
            'region_type' => $type,
        ]);

        return $id;
    }

    public function dismissRegion(string $userId, string $regionId): string
    {
        $row = $this->fetchOne(
            'SELECT r.id, r.evidence_id, r.status, c.user_id, c.status AS case_status
             FROM evidence_privacy_regions r
             INNER JOIN evidence_items e ON e.id = r.evidence_id
             INNER JOIN cases c ON c.id = e.case_id
             WHERE r.id = :id LIMIT 1',
            ['id' => $regionId]
        );

        if ($row === null) {
            throw new \DomainException('Privacy-Bereich nicht gefunden.');
        }

        $this->authorization->authorize($userId, 'evidence.upload', (string) $row['user_id']);
        $this->assertEditableStatus((string) $row['case_status']);

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(
                'UPDATE evidence_privacy_regions
                 SET status = "DISMISSED", updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $stmt->execute(['id' => $regionId]);

            $this->invalidateReview((string) $row['evidence_id']);
            $this->event((string) $row['evidence_id'], 'PRIVACY_REGION_DISMISSED', $userId, [
                'region_id' => $regionId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return (string) $row['evidence_id'];
    }

    public function confirmReview(string $userId, string $evidenceId): array
    {
        $evidence = $this->ownedEvidence($userId, $evidenceId, 'evidence.upload');
        $this->assertEditableStatus((string) $evidence['case_status']);

        $source = $this->sourceVariant($evidenceId);
        $regions = $this->regions($evidenceId);
        $canonicalRegions = array_map(
            static fn(array $region): array => [
                'id' => (string) $region['id'],
                'region_type' => (string) $region['region_type'],
                'x' => number_format((float) $region['x'], 7, '.', ''),
                'y' => number_format((float) $region['y'], 7, '.', ''),
                'width' => number_format((float) $region['width'], 7, '.', ''),
                'height' => number_format((float) $region['height'], 7, '.', ''),
            ],
            $regions
        );
        $regionsJson = json_encode(
            $canonicalRegions,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $regionsHash = hash('sha256', $regionsJson);

        $versionStmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(version_no), 0) + 1
             FROM evidence_versions
             WHERE evidence_id = :id AND variant = "PUBLIC"'
        );
        $versionStmt->execute(['id' => $evidenceId]);
        $version = (int) $versionStmt->fetchColumn();

        $public = $this->processor->createPublicCopy(
            (string) $evidence['case_id'],
            $evidenceId,
            (string) $source['storage_path'],
            (string) $source['mime_type'],
            $regions,
            $version
        );

        try {
            $this->pdo->beginTransaction();

            $insert = $this->pdo->prepare(
                'INSERT INTO evidence_versions
                 (id, evidence_id, variant, version_no, storage_path, mime_type, file_size, sha256,
                  width, height, processing_json, created_at)
                 VALUES
                 (:id, :evidence_id, "PUBLIC", :version_no, :path, :mime, :size, :sha256,
                  :width, :height, :processing, UTC_TIMESTAMP())'
            );
            $insert->execute([
                'id' => Uuid::v4(),
                'evidence_id' => $evidenceId,
                'version_no' => $version,
                'path' => $public['relative_path'],
                'mime' => $public['mime_type'],
                'size' => $public['size'],
                'sha256' => $public['sha256'],
                'width' => $public['width'],
                'height' => $public['height'],
                'processing' => json_encode(
                    array_merge($public['processing'], [
                        'regions_sha256' => $regionsHash,
                        'source_variant' => $source['variant'],
                        'source_version_no' => (int) $source['version_no'],
                        'source_sha256' => $source['sha256'],
                    ]),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
            ]);

            $review = $this->pdo->prepare(
                'INSERT INTO evidence_privacy_reviews
                 (evidence_id, public_version_no, regions_sha256, reviewed_by_user_id, reviewed_at)
                 VALUES (:evidence_id, :version_no, :regions_sha256, :user_id, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    public_version_no = VALUES(public_version_no),
                    regions_sha256 = VALUES(regions_sha256),
                    reviewed_by_user_id = VALUES(reviewed_by_user_id),
                    reviewed_at = VALUES(reviewed_at)'
            );
            $review->execute([
                'evidence_id' => $evidenceId,
                'version_no' => $version,
                'regions_sha256' => $regionsHash,
                'user_id' => $userId,
            ]);

            $this->event($evidenceId, 'PRIVACY_REVIEW_CONFIRMED', $userId, [
                'public_version_no' => $version,
                'regions_sha256' => $regionsHash,
                'region_count' => count($regions),
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->storage->deletePhysical((string) $public['relative_path']);
            throw $e;
        }

        $this->audit->log('EVIDENCE_PRIVACY_REVIEW_CONFIRMED', 'evidence', $evidenceId, 'USER', $userId, [
            'public_version_no' => $version,
            'regions_sha256' => $regionsHash,
            'region_count' => count($regions),
        ]);

        return [
            'public_version_no' => $version,
            'regions_sha256' => $regionsHash,
            'region_count' => count($regions),
        ];
    }

    public function preview(string $userId, string $evidenceId, string $variant): array
    {
        $this->ownedEvidence($userId, $evidenceId, 'case.view_own');

        $variant = strtoupper(trim($variant));
        if (!in_array($variant, ['WORKING','PUBLIC'], true)) {
            throw new \InvalidArgumentException('Diese Evidence-Variante darf nicht angezeigt werden.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT storage_path, mime_type, sha256, version_no
             FROM evidence_versions
             WHERE evidence_id = :id AND variant = :variant
             ORDER BY version_no DESC LIMIT 1'
        );
        $stmt->execute(['id' => $evidenceId, 'variant' => $variant]);
        $row = $stmt->fetch();

        if (!is_array($row) && $variant === 'WORKING') {
            $stmt = $this->pdo->prepare(
                'SELECT storage_path, mime_type, sha256, version_no
                 FROM evidence_versions
                 WHERE evidence_id = :id AND variant = "ORIGINAL"
                 ORDER BY version_no DESC LIMIT 1'
            );
            $stmt->execute(['id' => $evidenceId]);
            $row = $stmt->fetch();
        }

        if (!is_array($row)) {
            throw new \DomainException('Vorschau ist noch nicht verfügbar.');
        }

        $absolute = $this->storage->absolute((string) $row['storage_path']);

        if (!is_file($absolute)) {
            throw new \RuntimeException('Evidence-Datei fehlt im Storage.');
        }

        return [
            'body' => (string) file_get_contents($absolute),
            'mime_type' => (string) $row['mime_type'],
            'sha256' => (string) $row['sha256'],
            'version_no' => (int) $row['version_no'],
        ];
    }

    private function sourceVariant(string $evidenceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT storage_path, mime_type, variant, version_no, sha256
             FROM evidence_versions
             WHERE evidence_id = :id AND variant IN ("WORKING","ORIGINAL")
             ORDER BY CASE variant WHEN "WORKING" THEN 0 ELSE 1 END, version_no DESC
             LIMIT 1'
        );
        $stmt->execute(['id' => $evidenceId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new \RuntimeException('Keine verarbeitbare Evidence-Version vorhanden.');
        }

        return $row;
    }

    private function regions(string $evidenceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, region_type, x, y, width, height, source, created_at
             FROM evidence_privacy_regions
             WHERE evidence_id = :id AND status = "CONFIRMED"
             ORDER BY created_at, id'
        );
        $stmt->execute(['id' => $evidenceId]);

        return $stmt->fetchAll();
    }

    private function ownedEvidence(string $userId, string $evidenceId, string $permission): array
    {
        $row = $this->fetchOne(
            'SELECT e.id, e.case_id, e.category, e.original_filename, e.status,
                    c.user_id, c.status AS case_status
             FROM evidence_items e
             INNER JOIN cases c ON c.id = e.case_id
             WHERE e.id = :id LIMIT 1',
            ['id' => $evidenceId]
        );

        if ($row === null || $row['status'] !== 'ACTIVE') {
            throw new \DomainException('Aktiver Nachweis nicht gefunden.');
        }

        $this->authorization->authorize($userId, $permission, (string) $row['user_id']);

        return $row;
    }

    private function assertEditableStatus(string $status): void
    {
        if ($status !== CaseStatus::WAITING_FOR_EVIDENCE) {
            throw new \DomainException('Privacy-Bereiche können nur während der Beweiserfassung geändert werden.');
        }
    }

    private function assertRegion(float $x, float $y, float $width, float $height): void
    {
        if (
            $x < 0 || $y < 0 || $width <= 0 || $height <= 0
            || $x > 1 || $y > 1 || $width > 1 || $height > 1
            || ($x + $width) > 1.0000001
            || ($y + $height) > 1.0000001
        ) {
            throw new \InvalidArgumentException('Privacy-Bereich liegt außerhalb des Bildes.');
        }
    }

    private function invalidateReview(string $evidenceId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM evidence_privacy_reviews WHERE evidence_id = :id'
        );
        $stmt->execute(['id' => $evidenceId]);
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

    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
