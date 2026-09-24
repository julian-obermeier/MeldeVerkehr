<?php

declare(strict_types=1);

namespace MeldeVerkehr\Evidence;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Cases\CaseStatus;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class EvidenceReviewService
{
    private const CATEGORY_LABELS = [
        'OVERVIEW' => 'Übersichtsaufnahme',
        'PLATE' => 'Kennzeichenaufnahme',
        'VEHICLE_POSITION' => 'Fahrzeugposition',
        'OBSTRUCTION' => 'Dokumentation der Behinderung',
        'DANGER' => 'Dokumentation der Gefährdung',
        'PROPERTY_DAMAGE' => 'Dokumentation des Sachschadens',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization,
        private readonly EvidenceStorage $storage,
        private readonly AuditLogger $audit,
        private readonly CaseService $cases
    ) {
    }

    public function summary(string $userId, string $caseId): array
    {
        $summary = $this->collect($userId, $caseId);

        $items = array_map(static function (array $item): array {
            unset($item['storage_path']);
            return $item;
        }, $summary['items']);

        return array_merge($summary, ['items' => $items]);
    }

    public function confirm(
        string $userId,
        string $caseId,
        bool $warningsAcknowledged
    ): array {
        $summary = $this->collect($userId, $caseId);

        if ((string) $summary['case']['status'] !== CaseStatus::WAITING_FOR_EVIDENCE) {
            throw new \DomainException('Der Vorgang ist aktuell nicht in der Beweiserfassung.');
        }

        if ($summary['missing'] !== []) {
            throw new \DomainException('Die Beweismappe kann wegen fehlender Pflichtprüfungen noch nicht erstellt werden.');
        }

        if ($summary['warnings'] !== [] && !$warningsAcknowledged) {
            throw new \DomainException('Die Evidence-Hinweise müssen vor dem Fortfahren bestätigt werden.');
        }

        $reviewVersion = $this->nextVersion('case_evidence_reviews', 'review_version', $caseId);
        $packageVersion = $this->nextVersion('evidence_packages', 'version_no', $caseId);
        $reviewId = Uuid::v4();
        $packageId = Uuid::v4();
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');

        $manifestItems = [];
        foreach ($summary['items'] as $index => $item) {
            $manifestItems[] = [
                'order' => $index + 1,
                'evidence_id' => (string) $item['id'],
                'category' => (string) $item['category'],
                'variant' => 'PUBLIC',
                'version_no' => (int) $item['public_version_no'],
                'sha256' => (string) $item['public_sha256'],
                'original_sha256' => (string) $item['original_sha256'],
            ];
        }

        $manifest = [
            'schema_version' => 1,
            'case_id' => $caseId,
            'public_number' => (string) $summary['case']['public_number'],
            'package_version' => $packageVersion,
            'review_version' => $reviewVersion,
            'generated_at' => $generatedAt,
            'items' => $manifestItems,
        ];
        $manifestJson = json_encode(
            $manifest,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $manifestHash = hash('sha256', $manifestJson);

        try {
            $this->pdo->beginTransaction();

            $review = $this->pdo->prepare(
                'INSERT INTO case_evidence_reviews
                 (id, case_id, review_version, missing_json, warnings_json, acknowledged_warnings,
                  created_by_user_id, created_at, confirmed_at)
                 VALUES
                 (:id, :case_id, :review_version, :missing, :warnings, :acknowledged,
                  :user_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $review->execute([
                'id' => $reviewId,
                'case_id' => $caseId,
                'review_version' => $reviewVersion,
                'missing' => json_encode([], JSON_THROW_ON_ERROR),
                'warnings' => json_encode($summary['warnings'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'acknowledged' => $warningsAcknowledged ? 1 : 0,
                'user_id' => $userId,
            ]);

            $package = $this->pdo->prepare(
                'INSERT INTO evidence_packages
                 (id, case_id, version_no, status, manifest_json, manifest_sha256,
                  created_by_user_id, created_at, frozen_at)
                 VALUES
                 (:id, :case_id, :version_no, "FROZEN", :manifest, :manifest_sha256,
                  :user_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $package->execute([
                'id' => $packageId,
                'case_id' => $caseId,
                'version_no' => $packageVersion,
                'manifest' => $manifestJson,
                'manifest_sha256' => $manifestHash,
                'user_id' => $userId,
            ]);

            $itemInsert = $this->pdo->prepare(
                'INSERT INTO evidence_package_items
                 (package_id, evidence_id, order_no, variant, version_no, sha256_snapshot, category_snapshot)
                 VALUES
                 (:package_id, :evidence_id, :order_no, "PUBLIC", :version_no, :sha256, :category)'
            );

            foreach ($summary['items'] as $index => $item) {
                $itemInsert->execute([
                    'package_id' => $packageId,
                    'evidence_id' => $item['id'],
                    'order_no' => $index + 1,
                    'version_no' => $item['public_version_no'],
                    'sha256' => $item['public_sha256'],
                    'category' => $item['category'],
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        try {
            $this->cases->changeStatus(
                $userId,
                $caseId,
                CaseStatus::READY_FOR_REVIEW,
                'Beweismappe geprüft und revisionssicher eingefroren'
            );
        } catch (Throwable $e) {
            try {
                $this->pdo->beginTransaction();
                $this->pdo->prepare('DELETE FROM evidence_packages WHERE id = :id')
                    ->execute(['id' => $packageId]);
                $this->pdo->prepare('DELETE FROM case_evidence_reviews WHERE id = :id')
                    ->execute(['id' => $reviewId]);
                $this->pdo->commit();
            } catch (Throwable) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            }
            throw $e;
        }

        $this->audit->log('EVIDENCE_PACKAGE_FROZEN', 'case', $caseId, 'USER', $userId, [
            'package_id' => $packageId,
            'package_version' => $packageVersion,
            'manifest_sha256' => $manifestHash,
            'item_count' => count($summary['items']),
        ]);

        return [
            'package_id' => $packageId,
            'package_version' => $packageVersion,
            'manifest_sha256' => $manifestHash,
            'item_count' => count($summary['items']),
        ];
    }

    public function latestPackage(string $userId, string $caseId): ?array
    {
        $case = $this->ownedCase($userId, $caseId);

        $stmt = $this->pdo->prepare(
            'SELECT id, version_no, status, manifest_sha256, created_at, frozen_at
             FROM evidence_packages
             WHERE case_id = :case_id
             ORDER BY version_no DESC LIMIT 1'
        );
        $stmt->execute(['case_id' => $case['id']]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function collect(string $userId, string $caseId): array
    {
        $case = $this->ownedCase($userId, $caseId);

        $stmt = $this->pdo->prepare(
            'SELECT e.id, e.category, e.original_filename, e.quality_state, e.created_at,
                    pr.public_version_no, pr.reviewed_at,
                    pv.storage_path, pv.sha256 AS public_sha256, pv.mime_type, pv.file_size,
                    pv.width, pv.height,
                    ov.storage_path AS original_storage_path, ov.sha256 AS original_sha256
             FROM evidence_items e
             INNER JOIN evidence_versions ov
               ON ov.evidence_id = e.id AND ov.variant = "ORIGINAL" AND ov.version_no = 1
             LEFT JOIN evidence_privacy_reviews pr ON pr.evidence_id = e.id
             LEFT JOIN evidence_versions pv
               ON pv.evidence_id = e.id
              AND pv.variant = "PUBLIC"
              AND pv.version_no = pr.public_version_no
             WHERE e.case_id = :case_id AND e.status = "ACTIVE"
             ORDER BY e.created_at, e.id'
        );
        $stmt->execute(['case_id' => $caseId]);
        $items = $stmt->fetchAll();

        usort($items, fn(array $a, array $b): int =>
            ($this->categoryPriority((string) $a['category']) <=> $this->categoryPriority((string) $b['category']))
            ?: strcmp((string) $a['created_at'], (string) $b['created_at'])
        );

        $missing = [];
        $warnings = [];

        if ($items === []) {
            $missing[] = 'Mindestens ein aktiver Bildnachweis ist erforderlich.';
        }

        $categories = [];
        foreach ($items as $item) {
            $categories[(string) $item['category']] = true;

            $originalAbsolute = $this->storage->absolute((string) $item['original_storage_path']);
            if (
                !is_file($originalAbsolute)
                || !hash_equals((string) $item['original_sha256'], hash_file('sha256', $originalAbsolute))
            ) {
                $missing[] = sprintf(
                    'Integrität des geschützten Originals „%s“ konnte nicht bestätigt werden.',
                    (string) $item['original_filename']
                );
            }

            if ($item['public_version_no'] === null || $item['storage_path'] === null) {
                $missing[] = sprintf(
                    'Privacy-Prüfung für „%s“ ist noch nicht bestätigt.',
                    (string) $item['original_filename']
                );
                continue;
            }

            $absolute = $this->storage->absolute((string) $item['storage_path']);
            if (
                !is_file($absolute)
                || !hash_equals((string) $item['public_sha256'], hash_file('sha256', $absolute))
            ) {
                $missing[] = sprintf(
                    'Integrität der freigegebenen Kopie „%s“ konnte nicht bestätigt werden.',
                    (string) $item['original_filename']
                );
            }

            if ($item['quality_state'] === 'RETAKE_RECOMMENDED') {
                $warnings[] = sprintf(
                    '„%s“ hat die technische Einstufung „Neuaufnahme empfohlen“.',
                    (string) $item['original_filename']
                );
            } elseif ($item['quality_state'] === 'LIMITED') {
                $warnings[] = sprintf(
                    '„%s“ hat nur eingeschränkte technische Qualität.',
                    (string) $item['original_filename']
                );
            }
        }

        $suggested = ['OVERVIEW','PLATE','VEHICLE_POSITION'];
        if ((int) $case['obstruction'] === 1) {
            $suggested[] = 'OBSTRUCTION';
        }
        if ((int) $case['endangerment'] === 1) {
            $suggested[] = 'DANGER';
        }
        if ((int) $case['damage'] === 1) {
            $suggested[] = 'PROPERTY_DAMAGE';
        }

        foreach (array_values(array_unique($suggested)) as $category) {
            if (!isset($categories[$category])) {
                $warnings[] = 'Empfohlene Kategorie fehlt: ' . (self::CATEGORY_LABELS[$category] ?? $category) . '.';
            }
        }

        return [
            'case' => $case,
            'items' => $items,
            'missing' => array_values(array_unique($missing)),
            'warnings' => array_values(array_unique($warnings)),
            'ready' => $missing === [],
            'latest_package' => $this->latestPackageRaw($caseId),
        ];
    }

    private function ownedCase(string $userId, string $caseId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_number, user_id, status, obstruction, endangerment, damage
             FROM cases WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $caseId]);
        $case = $stmt->fetch();

        if (!is_array($case)) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        $this->authorization->authorize($userId, 'case.view_own', (string) $case['user_id']);

        return $case;
    }

    private function latestPackageRaw(string $caseId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, version_no, status, manifest_sha256, created_at, frozen_at
             FROM evidence_packages
             WHERE case_id = :case_id
             ORDER BY version_no DESC LIMIT 1'
        );
        $stmt->execute(['case_id' => $caseId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function nextVersion(string $table, string $column, string $caseId): int
    {
        $allowed = [
            'case_evidence_reviews' => 'review_version',
            'evidence_packages' => 'version_no',
        ];

        if (($allowed[$table] ?? null) !== $column) {
            throw new \InvalidArgumentException('Ungültiger Versionszähler.');
        }

        $stmt = $this->pdo->prepare(
            sprintf('SELECT COALESCE(MAX(%s), 0) + 1 FROM %s WHERE case_id = :case_id', $column, $table)
        );
        $stmt->execute(['case_id' => $caseId]);

        return max(1, (int) $stmt->fetchColumn());
    }

    private function categoryPriority(string $category): int
    {
        return match ($category) {
            'OVERVIEW' => 10,
            'VEHICLE_POSITION' => 20,
            'VEHICLE' => 30,
            'PLATE' => 40,
            'TRAFFIC_SIGN' => 50,
            'ADDITIONAL_SIGN' => 60,
            'TEMPORARY_SIGN' => 70,
            'PERMIT' => 80,
            'OBSTRUCTION' => 90,
            'DANGER' => 100,
            'PROPERTY_DAMAGE' => 110,
            'CONTEXT' => 120,
            default => 999,
        };
    }
}
