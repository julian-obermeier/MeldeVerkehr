<?php

declare(strict_types=1);

namespace MeldeVerkehr\Dispatch;

use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Cases\CaseStatus;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Uuid;
use MeldeVerkehr\Witness\WitnessService;
use PDO;

final class DispatchPackageService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization,
        private readonly CaseService $cases,
        private readonly WitnessService $witness,
        private readonly SecretCipher $cipher
    ) {
    }

    public function preview(
        string $userId,
        string $caseId,
        array $route,
        ?array $requirements
    ): array {
        $manifest = $this->buildManifest($userId, $caseId, $route, $requirements);
        $compatibility = $this->compatibility($manifest, $requirements);

        return [
            'manifest' => $manifest,
            'errors' => $compatibility['errors'],
            'warnings' => $compatibility['warnings'],
            'ready' => $compatibility['errors'] === [],
        ];
    }

    public function freeze(
        string $userId,
        string $caseId,
        array $route,
        ?array $requirements
    ): array {
        $preview = $this->preview($userId, $caseId, $route, $requirements);

        if (!$preview['ready']) {
            throw new \DomainException('Versandpaket erfüllt die Behördenanforderungen noch nicht.');
        }

        $manifest = $preview['manifest'];
        $json = json_encode(
            $manifest,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $hash = hash('sha256', $json);
        $id = Uuid::v4();
        $version = $this->nextVersion($caseId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO dispatch_packages
             (id, case_id, version_no, authority_id, endpoint_id, witness_report_id,
              evidence_package_id, quality_review_id, manifest_encrypted, manifest_sha256,
              status, created_by_user_id, created_at, frozen_at)
             VALUES
             (:id, :case_id, :version_no, :authority_id, :endpoint_id, :witness_report_id,
              :evidence_package_id, :quality_review_id, :manifest, :manifest_sha256,
              "FROZEN", :user_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'id' => $id,
            'case_id' => $caseId,
            'version_no' => $version,
            'authority_id' => $route['authority_id'],
            'endpoint_id' => $route['endpoint_id'],
            'witness_report_id' => $manifest['witness_report']['id'],
            'evidence_package_id' => $manifest['evidence_package']['id'],
            'quality_review_id' => $manifest['quality_review']['id'],
            'manifest' => $this->cipher->encrypt($json),
            'manifest_sha256' => $hash,
            'user_id' => $userId,
        ]);

        return [
            'id' => $id,
            'version_no' => $version,
            'manifest_sha256' => $hash,
            'manifest' => $manifest,
        ];
    }

    public function load(string $packageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM dispatch_packages WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $packageId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new \DomainException('Versandpaket nicht gefunden.');
        }

        $json = $this->cipher->decrypt((string) $row['manifest_encrypted']);

        if (!hash_equals((string) $row['manifest_sha256'], hash('sha256', $json))) {
            throw new \RuntimeException('Integrität des Versandpakets ist verletzt.');
        }

        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new \RuntimeException('Versandpaket ist ungültig.');
        }

        $row['manifest'] = $manifest;
        unset($row['manifest_encrypted']);

        return $row;
    }

    private function buildManifest(
        string $userId,
        string $caseId,
        array $route,
        ?array $requirements
    ): array {
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

        $report = $this->witness->currentConfirmedReport($userId, $caseId);
        if ($report === null) {
            throw new \DomainException('Aktueller bestätigter Zeugenbericht fehlt.');
        }

        $quality = $this->latestQualityReview($caseId);
        if ($quality === null) {
            throw new \DomainException('Bestätigter finaler Qualitätsreview fehlt.');
        }

        $evidencePackage = $this->latestEvidencePackage($caseId);
        if ($evidencePackage === null) {
            throw new \DomainException('Eingefrorene Beweismappe fehlt.');
        }

        $reporter = $this->reporter($userId);
        $evidenceItems = $this->evidenceItems((string) $evidencePackage['id']);

        return [
            'schema_version' => 1,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'case' => [
                'id' => $caseId,
                'public_number' => (string) $caseData['case']['public_number'],
            ],
            'reporter' => $reporter,
            'authority' => [
                'id' => (string) $route['authority_id'],
                'name' => (string) $route['authority_name'],
                'endpoint_id' => (string) $route['endpoint_id'],
                'channel' => (string) $route['channel'],
                'endpoint_value' => (string) $route['endpoint_value'],
            ],
            'routing' => [
                'rule_id' => (string) $route['rule_id'],
                'certainty' => (string) $route['certainty'],
                'matched_on' => array_values($route['matched_on'] ?? []),
                'score' => (int) ($route['score'] ?? 0),
            ],
            'witness_report' => [
                'id' => (string) $report['id'],
                'version_no' => (int) $report['version_no'],
                'snapshot_sha256' => (string) $report['snapshot_sha256'],
                'snapshot' => $report['snapshot'],
                'confirmed_at' => $report['confirmed_at'],
            ],
            'evidence_package' => [
                'id' => (string) $evidencePackage['id'],
                'version_no' => (int) $evidencePackage['version_no'],
                'manifest_sha256' => (string) $evidencePackage['manifest_sha256'],
                'items' => $evidenceItems,
            ],
            'quality_review' => [
                'id' => (string) $quality['id'],
                'version_no' => (int) $quality['version_no'],
                'confirmed_at' => $quality['confirmed_at'],
            ],
            'requirements' => $requirements === null ? null : [
                'id' => $requirements['id'],
                'version_no' => (int) $requirements['version_no'],
                'required_fields' => $requirements['required_fields'],
                'accepted_mime' => $requirements['accepted_mime'],
                'max_attachment_bytes' => $requirements['max_attachment_bytes'],
                'max_total_bytes' => $requirements['max_total_bytes'],
            ],
        ];
    }

    private function compatibility(array $manifest, ?array $requirements): array
    {
        $errors = [];
        $warnings = [];

        if ($requirements === null) {
            $warnings[] = 'Für diese Behörde ist noch kein verifiziertes Anforderungsprofil hinterlegt.';
            return ['errors' => $errors, 'warnings' => $warnings];
        }

        foreach ($requirements['required_fields'] as $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }

            $value = $this->path($manifest, $path);
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $errors[] = 'Pflichtfeld fehlt: ' . $path;
            }
        }

        $accepted = array_values(array_filter(
            $requirements['accepted_mime'],
            'is_string'
        ));
        $maxAttachment = $requirements['max_attachment_bytes'] === null
            ? null
            : (int) $requirements['max_attachment_bytes'];
        $maxTotal = $requirements['max_total_bytes'] === null
            ? null
            : (int) $requirements['max_total_bytes'];

        $total = 0;
        foreach ($manifest['evidence_package']['items'] as $item) {
            $size = (int) $item['size'];
            $total += $size;

            if ($accepted !== [] && !in_array((string) $item['mime_type'], $accepted, true)) {
                $errors[] = 'Nicht akzeptierter Dateityp: ' . $item['mime_type'];
            }

            if ($maxAttachment !== null && $size > $maxAttachment) {
                $errors[] = 'Ein Anhang überschreitet das Größenlimit.';
            }
        }

        if ($maxTotal !== null && $total > $maxTotal) {
            $errors[] = 'Die Beweisanlagen überschreiten das Gesamtgrößenlimit.';
        }

        return [
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function evidenceItems(string $evidencePackageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT epi.evidence_id, epi.order_no, epi.variant, epi.version_no,
                    epi.sha256_snapshot, epi.category_snapshot,
                    ev.mime_type, ev.file_size
             FROM evidence_package_items epi
             INNER JOIN evidence_versions ev
               ON ev.evidence_id = epi.evidence_id
              AND ev.variant = epi.variant
              AND ev.version_no = epi.version_no
             WHERE epi.package_id = :package_id
             ORDER BY epi.order_no'
        );
        $stmt->execute(['package_id' => $evidencePackageId]);

        return array_map(static fn(array $row): array => [
            'evidence_id' => (string) $row['evidence_id'],
            'order_no' => (int) $row['order_no'],
            'variant' => (string) $row['variant'],
            'version_no' => (int) $row['version_no'],
            'sha256' => (string) $row['sha256_snapshot'],
            'category' => (string) $row['category_snapshot'],
            'mime_type' => (string) $row['mime_type'],
            'size' => (int) $row['file_size'],
        ], $stmt->fetchAll());
    }

    private function reporter(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT first_name, last_name, email FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new \RuntimeException('Meldende Person konnte nicht geladen werden.');
        }

        return [
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'email' => (string) $row['email'],
        ];
    }

    private function latestQualityReview(string $caseId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, version_no, confirmed_at
             FROM case_quality_reviews
             WHERE case_id = :case_id AND confirmed_at IS NOT NULL
             ORDER BY version_no DESC LIMIT 1'
        );
        $stmt->execute(['case_id' => $caseId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function latestEvidencePackage(string $caseId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, version_no, manifest_sha256
             FROM evidence_packages
             WHERE case_id = :case_id AND status = "FROZEN"
             ORDER BY version_no DESC LIMIT 1'
        );
        $stmt->execute(['case_id' => $caseId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function path(array $data, string $path): mixed
    {
        $value = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function nextVersion(string $caseId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(version_no), 0) + 1
             FROM dispatch_packages WHERE case_id = :case_id'
        );
        $stmt->execute(['case_id' => $caseId]);

        return max(1, (int) $stmt->fetchColumn());
    }
}
