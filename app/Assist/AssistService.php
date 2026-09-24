<?php

declare(strict_types=1);

namespace MeldeVerkehr\Assist;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Cases\CaseStatus;
use MeldeVerkehr\Evidence\EvidenceStorage;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class AssistService
{
    private const PURPOSES = [
        'PLATE_OCR',
        'TRAFFIC_SIGNS',
        'FULL_VISION',
        'OFFENSE_SUGGESTIONS',
    ];

    private const TYPES = [
        'LICENSE_PLATE',
        'TRAFFIC_SIGN',
        'ADDITIONAL_SIGN',
        'OFFENSE',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization,
        private readonly CaseService $cases,
        private readonly EvidenceStorage $storage,
        private readonly SecretCipher $cipher,
        private readonly VisionProviderInterface $provider,
        private readonly AuditLogger $audit,
        private readonly ImageQualityAnalyzer $qualityAnalyzer = new ImageQualityAnalyzer()
    ) {
    }

    public function providerStatus(): array
    {
        return [
            'name' => $this->provider->name(),
            'enabled' => $this->provider->enabled(),
        ];
    }

    public function overview(string $userId, string $caseId): array
    {
        $case = $this->cases->findOwned($userId, $caseId);
        if ($case === null) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        $this->authorization->authorize(
            $userId,
            'case.view_own',
            (string) $case['case']['user_id']
        );

        $qualityStmt = $this->pdo->prepare(
            'SELECT e.id AS evidence_id, e.category, e.original_filename, e.status AS evidence_status,
                    e.quality_state,
                    q.source_variant, q.brightness_mean, q.contrast_stddev, q.sharpness_score,
                    q.resolution_state, q.brightness_state, q.contrast_state, q.sharpness_state,
                    q.overall_state, q.metrics_json, q.created_at
             FROM evidence_items e
             LEFT JOIN evidence_quality_metrics q
               ON q.evidence_id = e.id
              AND q.version_no = (
                    SELECT MAX(q2.version_no)
                    FROM evidence_quality_metrics q2
                    WHERE q2.evidence_id = e.id
              )
             WHERE e.case_id = :case_id
             ORDER BY e.created_at DESC'
        );
        $qualityStmt->execute(['case_id' => $caseId]);
        $evidence = $qualityStmt->fetchAll();

        foreach ($evidence as &$row) {
            $metrics = $row['metrics_json'] === null
                ? null
                : json_decode((string) $row['metrics_json'], true);
            $row['warnings'] = is_array($metrics) && is_array($metrics['warnings'] ?? null)
                ? $metrics['warnings']
                : [];
            unset($row['metrics_json']);
        }
        unset($row);

        $suggestionStmt = $this->pdo->prepare(
            'SELECT s.id, s.run_id, s.evidence_id, s.suggestion_type, s.value_encrypted,
                    s.confidence, s.status, s.created_at, s.decided_at,
                    r.provider, r.purpose
             FROM assist_suggestions s
             INNER JOIN assist_runs r ON r.id = s.run_id
             WHERE s.case_id = :case_id
             ORDER BY s.created_at DESC, s.id DESC'
        );
        $suggestionStmt->execute(['case_id' => $caseId]);
        $suggestions = $suggestionStmt->fetchAll();

        foreach ($suggestions as &$suggestion) {
            $json = $this->cipher->decrypt((string) $suggestion['value_encrypted']);
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $suggestion['value'] = is_array($value) ? $value : [];
            $suggestion['confidence'] = $suggestion['confidence'] === null
                ? null
                : (float) $suggestion['confidence'];
            unset($suggestion['value_encrypted']);
        }
        unset($suggestion);

        $runStmt = $this->pdo->prepare(
            'SELECT id, evidence_id, provider, purpose, status, input_variant,
                    input_sha256, output_sha256, metadata_json, error_message,
                    created_at, completed_at
             FROM assist_runs
             WHERE case_id = :case_id
             ORDER BY created_at DESC
             LIMIT 50'
        );
        $runStmt->execute(['case_id' => $caseId]);
        $runs = $runStmt->fetchAll();

        foreach ($runs as &$run) {
            $run['metadata'] = $run['metadata_json'] === null
                ? null
                : json_decode((string) $run['metadata_json'], true);
            unset($run['metadata_json']);
        }
        unset($run);

        return [
            'case_data' => $case,
            'provider' => $this->providerStatus(),
            'evidence' => $evidence,
            'suggestions' => $suggestions,
            'runs' => $runs,
            'editable' => (string) $case['case']['status'] === CaseStatus::WAITING_FOR_EVIDENCE,
        ];
    }

    public function analyzeQuality(string $userId, string $evidenceId): array
    {
        $source = $this->editableEvidence($userId, $evidenceId);
        $absolute = $this->storage->absolute((string) $source['storage_path']);

        if (!is_file($absolute) || !is_readable($absolute)) {
            throw new \RuntimeException('Analysebild fehlt im geschützten Storage.');
        }

        if (!hash_equals((string) $source['sha256'], hash_file('sha256', $absolute))) {
            throw new \RuntimeException('Integrität der Analysequelle ist verletzt.');
        }

        $metrics = $this->qualityAnalyzer->analyze(
            $absolute,
            (string) $source['mime_type']
        );

        if (!is_array($metrics)) {
            throw new \DomainException(
                'Lokale Qualitätsanalyse ist auf diesem Server nicht verfügbar.'
            );
        }

        $versionStmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(version_no), 0) + 1
             FROM evidence_quality_metrics
             WHERE evidence_id = :evidence_id'
        );
        $versionStmt->execute(['evidence_id' => $evidenceId]);
        $version = max(1, (int) $versionStmt->fetchColumn());
        $id = Uuid::v4();

        $stmt = $this->pdo->prepare(
            'INSERT INTO evidence_quality_metrics
             (id, evidence_id, version_no, source_variant, width, height,
              brightness_mean, contrast_stddev, sharpness_score,
              resolution_state, brightness_state, contrast_state, sharpness_state,
              overall_state, metrics_json, created_at)
             VALUES
             (:id, :evidence_id, :version_no, :source_variant, :width, :height,
              :brightness_mean, :contrast_stddev, :sharpness_score,
              :resolution_state, :brightness_state, :contrast_state, :sharpness_state,
              :overall_state, :metrics_json, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'id' => $id,
            'evidence_id' => $evidenceId,
            'version_no' => $version,
            'source_variant' => $source['variant'],
            'width' => $metrics['width'],
            'height' => $metrics['height'],
            'brightness_mean' => $metrics['brightness_mean'],
            'contrast_stddev' => $metrics['contrast_stddev'],
            'sharpness_score' => $metrics['sharpness_score'],
            'resolution_state' => $metrics['resolution_state'],
            'brightness_state' => $metrics['brightness_state'],
            'contrast_state' => $metrics['contrast_state'],
            'sharpness_state' => $metrics['sharpness_state'],
            'overall_state' => $metrics['overall_state'],
            'metrics_json' => json_encode(
                $metrics,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
        ]);

        $this->pdo->prepare(
            'UPDATE evidence_items
             SET quality_state = :quality_state, updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        )->execute([
            'quality_state' => $metrics['overall_state'],
            'id' => $evidenceId,
        ]);

        $this->audit->log(
            'EVIDENCE_LOCAL_QUALITY_ANALYZED',
            'evidence',
            $evidenceId,
            'USER',
            $userId,
            [
                'case_id' => $source['case_id'],
                'version_no' => $version,
                'source_variant' => $source['variant'],
                'input_sha256' => $source['sha256'],
                'algorithm' => $metrics['algorithm'],
                'overall_state' => $metrics['overall_state'],
            ]
        );

        return [
            'id' => $id,
            'version_no' => $version,
            'evidence_id' => $evidenceId,
            'metrics' => $metrics,
        ];
    }

    public function analyzeEvidence(
        string $userId,
        string $evidenceId,
        string $purpose
    ): array {
        $purpose = strtoupper(trim($purpose));

        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException('Unbekannter Assistenzzweck.');
        }

        if (!$this->provider->enabled()) {
            throw new \DomainException(
                'Vision/OCR ist deaktiviert. Es werden keine Bilder an externe Dienste übertragen.'
            );
        }

        $source = $this->editableEvidence($userId, $evidenceId);
        $context = $this->analysisContext((string) $source['case_id'], $purpose);

        if ($purpose === 'OFFENSE_SUGGESTIONS' && ($context['confirmed_signals'] ?? []) === []) {
            throw new \DomainException(
                'Tatbestandsvorschläge werden erst aus zuvor bestätigten Schild-/Zusatzzeichenhinweisen erzeugt.'
            );
        }

        $absolute = $this->storage->absolute((string) $source['storage_path']);

        if (!is_file($absolute) || !is_readable($absolute)) {
            throw new \RuntimeException('Analysebild fehlt im geschützten Storage.');
        }

        if (!hash_equals((string) $source['sha256'], hash_file('sha256', $absolute))) {
            throw new \RuntimeException('Integrität der Analysequelle ist verletzt.');
        }

        $runId = Uuid::v4();

        $stmt = $this->pdo->prepare(
            'INSERT INTO assist_runs
             (id, case_id, evidence_id, provider, purpose, status, input_variant,
              input_sha256, output_sha256, metadata_json, error_message,
              requested_by_user_id, created_at, completed_at)
             VALUES
             (:id, :case_id, :evidence_id, :provider, :purpose, "RUNNING", :variant,
              :input_sha256, NULL, NULL, NULL, :user_id, UTC_TIMESTAMP(), NULL)'
        );
        $stmt->execute([
            'id' => $runId,
            'case_id' => $source['case_id'],
            'evidence_id' => $evidenceId,
            'provider' => $this->provider->name(),
            'purpose' => $purpose,
            'variant' => $source['variant'],
            'input_sha256' => $source['sha256'],
            'user_id' => $userId,
        ]);

        try {
            $result = $this->provider->analyze(
                $purpose,
                $absolute,
                (string) $source['mime_type'],
                $context
            );

            $rawSuggestions = is_array($result['suggestions'] ?? null)
                ? $result['suggestions']
                : [];

            $normalized = [];
            foreach ($rawSuggestions as $suggestion) {
                if (!is_array($suggestion)) {
                    continue;
                }

                $candidate = $this->normalizeSuggestion(
                    $purpose,
                    $suggestion,
                    (string) $source['case_id']
                );

                if ($candidate !== null) {
                    $normalized[] = $candidate;
                }
            }

            $outputJson = json_encode(
                [
                    'suggestions' => $normalized,
                    'provider_metadata' => is_array($result['metadata'] ?? null)
                        ? $result['metadata']
                        : [],
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $outputHash = hash('sha256', $outputJson);

            $types = array_values(array_unique(array_map(
                static fn(array $item): string => $item['type'],
                $normalized
            )));

            try {
                $this->pdo->beginTransaction();

                $insert = $this->pdo->prepare(
                    'INSERT INTO assist_suggestions
                     (id, run_id, case_id, evidence_id, suggestion_type, value_encrypted,
                      confidence, status, decided_by_user_id, created_at, decided_at)
                     VALUES
                     (:id, :run_id, :case_id, :evidence_id, :type, :value,
                      :confidence, "PENDING", NULL, UTC_TIMESTAMP(), NULL)'
                );

                foreach ($normalized as $suggestion) {
                    $valueJson = json_encode(
                        $suggestion['value'],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    );

                    $insert->execute([
                        'id' => Uuid::v4(),
                        'run_id' => $runId,
                        'case_id' => $source['case_id'],
                        'evidence_id' => $evidenceId,
                        'type' => $suggestion['type'],
                        'value' => $this->cipher->encrypt($valueJson),
                        'confidence' => $suggestion['confidence'],
                    ]);
                }

                $update = $this->pdo->prepare(
                    'UPDATE assist_runs
                     SET status = "COMPLETED", output_sha256 = :output_sha256,
                         metadata_json = :metadata, completed_at = UTC_TIMESTAMP()
                     WHERE id = :id'
                );
                $update->execute([
                    'output_sha256' => $outputHash,
                    'metadata' => json_encode(
                        [
                            'suggestion_count' => count($normalized),
                            'suggestion_types' => $types,
                            'provider_metadata_keys' => array_values(array_map(
                                'strval',
                                array_keys(is_array($result['metadata'] ?? null) ? $result['metadata'] : [])
                            )),
                        ],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    ),
                    'id' => $runId,
                ]);

                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }

            $this->audit->log(
                'ASSIST_RUN_COMPLETED',
                'case',
                (string) $source['case_id'],
                'USER',
                $userId,
                [
                    'run_id' => $runId,
                    'evidence_id' => $evidenceId,
                    'provider' => $this->provider->name(),
                    'purpose' => $purpose,
                    'input_sha256' => $source['sha256'],
                    'output_sha256' => $outputHash,
                    'suggestion_count' => count($normalized),
                ]
            );

            return [
                'run_id' => $runId,
                'status' => 'COMPLETED',
                'suggestion_count' => count($normalized),
                'suggestion_types' => $types,
            ];
        } catch (Throwable $e) {
            $this->pdo->prepare(
                'UPDATE assist_runs
                 SET status = "FAILED", error_message = :error, completed_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            )->execute([
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'id' => $runId,
            ]);

            $this->audit->log(
                'ASSIST_RUN_FAILED',
                'case',
                (string) $source['case_id'],
                'USER',
                $userId,
                [
                    'run_id' => $runId,
                    'evidence_id' => $evidenceId,
                    'provider' => $this->provider->name(),
                    'purpose' => $purpose,
                ]
            );

            throw $e;
        }
    }

    public function decideSuggestion(
        string $userId,
        string $suggestionId,
        bool $confirm
    ): string {
        $row = $this->ownedSuggestion($userId, $suggestionId);

        if ($row['status'] !== 'PENDING') {
            throw new \DomainException('Dieser Vorschlag wurde bereits entschieden.');
        }

        if ($row['case_status'] !== CaseStatus::WAITING_FOR_EVIDENCE) {
            throw new \DomainException('Assistenzvorschläge können in diesem Vorgangsstatus nicht mehr geändert werden.');
        }

        $status = $confirm ? 'CONFIRMED' : 'REJECTED';

        $stmt = $this->pdo->prepare(
            'UPDATE assist_suggestions
             SET status = :status, decided_by_user_id = :user_id, decided_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = "PENDING"'
        );
        $stmt->execute([
            'status' => $status,
            'user_id' => $userId,
            'id' => $suggestionId,
        ]);

        $this->audit->log(
            $confirm ? 'ASSIST_SUGGESTION_CONFIRMED' : 'ASSIST_SUGGESTION_REJECTED',
            'case',
            (string) $row['case_id'],
            'USER',
            $userId,
            [
                'suggestion_id' => $suggestionId,
                'suggestion_type' => $row['suggestion_type'],
            ]
        );

        return (string) $row['case_id'];
    }

    public function applyPlateSuggestion(string $userId, string $suggestionId): string
    {
        $row = $this->ownedSuggestion($userId, $suggestionId);

        if ($row['suggestion_type'] !== 'LICENSE_PLATE' || $row['status'] !== 'CONFIRMED') {
            throw new \DomainException('Nur bestätigte Kennzeichenvorschläge können übernommen werden.');
        }

        if ($row['case_status'] !== CaseStatus::WAITING_FOR_EVIDENCE) {
            throw new \DomainException('Kennzeichen kann in diesem Vorgangsstatus nicht über Assistenz übernommen werden.');
        }

        $value = $this->decryptValue((string) $row['value_encrypted']);
        $plate = trim((string) ($value['plate'] ?? ''));

        if ($plate === '') {
            throw new \DomainException('Kennzeichenvorschlag enthält keinen gültigen Wert.');
        }

        $case = $this->cases->findOwned($userId, (string) $row['case_id']);
        $vehicle = $case['vehicle'] ?? null;

        if (!is_array($vehicle)) {
            throw new \DomainException('Fahrzeugdaten fehlen.');
        }

        $this->cases->saveVehicle(
            $userId,
            (string) $row['case_id'],
            [
                'license_plate' => $plate,
                'vehicle_type' => $vehicle['vehicle_type'],
                'color' => $vehicle['color'],
                'make' => $vehicle['make'],
                'model' => $vehicle['model'],
            ]
        );

        $this->pdo->prepare(
            'UPDATE assist_suggestions SET status = "APPLIED" WHERE id = :id'
        )->execute(['id' => $suggestionId]);

        $this->audit->log(
            'ASSIST_PLATE_APPLIED',
            'case',
            (string) $row['case_id'],
            'USER',
            $userId,
            ['suggestion_id' => $suggestionId]
        );

        return (string) $row['case_id'];
    }

    public function applyOffenseSuggestion(string $userId, string $suggestionId): string
    {
        $row = $this->ownedSuggestion($userId, $suggestionId);

        if ($row['suggestion_type'] !== 'OFFENSE' || $row['status'] !== 'CONFIRMED') {
            throw new \DomainException('Nur bestätigte Tatbestandsvorschläge können übernommen werden.');
        }

        if ($row['case_status'] !== CaseStatus::WAITING_FOR_EVIDENCE) {
            throw new \DomainException('Tatbestand kann in diesem Vorgangsstatus nicht über Assistenz übernommen werden.');
        }

        $value = $this->decryptValue((string) $row['value_encrypted']);
        $stableKey = trim((string) ($value['stable_key'] ?? ''));

        $version = $this->latestOffenseVersion($stableKey);
        if ($version === null) {
            throw new \DomainException('Vorgeschlagener Tatbestand ist lokal nicht verfügbar.');
        }

        $this->cases->setPrimaryOffense(
            $userId,
            (string) $row['case_id'],
            (string) $version['id']
        );

        $this->pdo->prepare(
            'UPDATE assist_suggestions SET status = "APPLIED" WHERE id = :id'
        )->execute(['id' => $suggestionId]);

        $this->audit->log(
            'ASSIST_OFFENSE_APPLIED',
            'case',
            (string) $row['case_id'],
            'USER',
            $userId,
            [
                'suggestion_id' => $suggestionId,
                'stable_key' => $stableKey,
                'offense_version_id' => $version['id'],
            ]
        );

        return (string) $row['case_id'];
    }

    private function editableEvidence(string $userId, string $evidenceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id, e.case_id, e.status, c.user_id, c.status AS case_status,
                    v.variant, v.storage_path, v.mime_type, v.sha256
             FROM evidence_items e
             INNER JOIN cases c ON c.id = e.case_id
             INNER JOIN evidence_versions v ON v.evidence_id = e.id
             WHERE e.id = :id
               AND e.status = "ACTIVE"
               AND v.variant IN ("WORKING","ORIGINAL")
             ORDER BY CASE v.variant WHEN "WORKING" THEN 0 ELSE 1 END, v.version_no DESC
             LIMIT 1'
        );
        $stmt->execute(['id' => $evidenceId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new \DomainException('Aktiver Bildnachweis nicht gefunden.');
        }

        $this->authorization->authorize(
            $userId,
            'case.edit_own',
            (string) $row['user_id']
        );

        if ((string) $row['case_status'] !== CaseStatus::WAITING_FOR_EVIDENCE) {
            throw new \DomainException('Vision/OCR kann nur während der Beweiserfassung neu ausgeführt werden.');
        }

        return $row;
    }

    private function ownedSuggestion(string $userId, string $suggestionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, c.user_id, c.status AS case_status
             FROM assist_suggestions s
             INNER JOIN cases c ON c.id = s.case_id
             WHERE s.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $suggestionId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new \DomainException('Assistenzvorschlag nicht gefunden.');
        }

        $this->authorization->authorize(
            $userId,
            'case.edit_own',
            (string) $row['user_id']
        );

        return $row;
    }

    private function analysisContext(string $caseId, string $purpose): array
    {
        $context = [
            'purpose' => $purpose,
            'confirmed_signals' => [],
        ];

        if ($purpose !== 'OFFENSE_SUGGESTIONS') {
            return $context;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, suggestion_type, value_encrypted
             FROM assist_suggestions
             WHERE case_id = :case_id
               AND suggestion_type IN ("TRAFFIC_SIGN","ADDITIONAL_SIGN")
               AND status IN ("CONFIRMED","APPLIED")
             ORDER BY created_at, id'
        );
        $stmt->execute(['case_id' => $caseId]);

        foreach ($stmt->fetchAll() as $row) {
            $context['confirmed_signals'][] = [
                'suggestion_id' => (string) $row['id'],
                'type' => (string) $row['suggestion_type'],
                'value' => $this->decryptValue((string) $row['value_encrypted']),
            ];
        }

        return $context;
    }

    private function normalizeSuggestion(
        string $purpose,
        array $suggestion,
        string $caseId
    ): ?array {
        $type = strtoupper(trim((string) ($suggestion['type'] ?? '')));
        if (!in_array($type, self::TYPES, true)) {
            return null;
        }

        $allowedByPurpose = match ($purpose) {
            'PLATE_OCR' => ['LICENSE_PLATE'],
            'TRAFFIC_SIGNS' => ['TRAFFIC_SIGN','ADDITIONAL_SIGN'],
            'FULL_VISION' => ['LICENSE_PLATE','TRAFFIC_SIGN','ADDITIONAL_SIGN'],
            'OFFENSE_SUGGESTIONS' => ['OFFENSE'],
            default => [],
        };

        if (!in_array($type, $allowedByPurpose, true)) {
            return null;
        }

        $value = is_array($suggestion['value'] ?? null)
            ? $suggestion['value']
            : [];

        $value = match ($type) {
            'LICENSE_PLATE' => $this->normalizePlate($value),
            'TRAFFIC_SIGN','ADDITIONAL_SIGN' => $this->normalizeSign($value),
            'OFFENSE' => $this->normalizeOffense($value, $caseId),
            default => null,
        };

        if ($value === null) {
            return null;
        }

        $confidence = $suggestion['confidence'] ?? null;
        $confidence = is_numeric($confidence)
            ? max(0.0, min(1.0, (float) $confidence))
            : null;

        return [
            'type' => $type,
            'value' => $value,
            'confidence' => $confidence,
        ];
    }

    private function normalizePlate(array $value): ?array
    {
        $plate = trim((string) ($value['plate'] ?? ''));
        if ($plate === '' || mb_strlen($plate) > 40) {
            return null;
        }

        $result = ['plate' => $plate];

        if (is_array($value['region'] ?? null)) {
            $result['region'] = $this->normalizedRegion($value['region']);
        }

        return $result;
    }

    private function normalizeSign(array $value): ?array
    {
        $code = mb_substr(trim((string) ($value['code'] ?? '')), 0, 80);
        $label = mb_substr(trim((string) ($value['label'] ?? '')), 0, 250);
        $text = mb_substr(trim((string) ($value['text'] ?? '')), 0, 500);

        if ($code === '' && $label === '' && $text === '') {
            return null;
        }

        $result = [];
        if ($code !== '') {
            $result['code'] = $code;
        }
        if ($label !== '') {
            $result['label'] = $label;
        }
        if ($text !== '') {
            $result['text'] = $text;
        }
        if (is_array($value['region'] ?? null)) {
            $result['region'] = $this->normalizedRegion($value['region']);
        }

        return $result;
    }

    private function normalizeOffense(array $value, string $caseId): ?array
    {
        $stableKey = trim((string) ($value['stable_key'] ?? ''));
        if ($stableKey === '' || $stableKey === 'UNCLASSIFIED_PARKING') {
            return null;
        }

        $version = $this->latestOffenseVersion($stableKey);
        if ($version === null) {
            return null;
        }

        $basisIds = array_values(array_filter(array_map(
            'strval',
            is_array($value['basis_suggestion_ids'] ?? null)
                ? $value['basis_suggestion_ids']
                : []
        )));

        if (!$this->allBasisConfirmed($caseId, $basisIds)) {
            return null;
        }

        return [
            'stable_key' => $stableKey,
            'offense_version_id' => (string) $version['id'],
            'title' => (string) $version['title'],
            'basis_suggestion_ids' => $basisIds,
            'rationale' => mb_substr(trim((string) ($value['rationale'] ?? '')), 0, 1000),
        ];
    }

    private function allBasisConfirmed(string $caseId, array $basisIds): bool
    {
        if ($basisIds === []) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($basisIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM assist_suggestions
             WHERE case_id = ?
               AND id IN (' . $placeholders . ')
               AND suggestion_type IN ("TRAFFIC_SIGN","ADDITIONAL_SIGN")
               AND status IN ("CONFIRMED","APPLIED")'
        );
        $stmt->execute(array_merge([$caseId], $basisIds));

        return (int) $stmt->fetchColumn() === count(array_unique($basisIds));
    }

    private function latestOffenseVersion(string $stableKey): ?array
    {
        if ($stableKey === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT ov.id, ov.title, o.stable_key
             FROM offenses o
             INNER JOIN offense_versions ov ON ov.offense_id = o.id
             WHERE o.stable_key = :stable_key AND o.active = 1
             ORDER BY ov.version DESC LIMIT 1'
        );
        $stmt->execute(['stable_key' => $stableKey]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function normalizedRegion(array $region): array
    {
        $x = max(0.0, min(1.0, (float) ($region['x'] ?? 0)));
        $y = max(0.0, min(1.0, (float) ($region['y'] ?? 0)));
        $width = max(0.0, min(1.0 - $x, (float) ($region['width'] ?? 0)));
        $height = max(0.0, min(1.0 - $y, (float) ($region['height'] ?? 0)));

        return [
            'x' => round($x, 6),
            'y' => round($y, 6),
            'width' => round($width, 6),
            'height' => round($height, 6),
        ];
    }

    private function decryptValue(string $encrypted): array
    {
        $json = $this->cipher->decrypt($encrypted);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
