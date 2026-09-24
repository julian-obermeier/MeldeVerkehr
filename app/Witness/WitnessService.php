<?php

declare(strict_types=1);

namespace MeldeVerkehr\Witness;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Cases\CaseStatus;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class WitnessService
{
    public const DECLARATION_KEY = 'OWN_OBSERVATION_CONFIRMATION';
    public const DECLARATION_VERSION = 1;
    public const DECLARATION_TEXT = 'Ich bestätige, dass die in diesem Zeugenbericht als eigene Beobachtung gekennzeichneten Angaben nach bestem Wissen auf meiner eigenen Wahrnehmung beruhen und die von mir bestätigten Vorgangsdaten korrekt wiedergegeben sind. Diese Bestätigung ist keine eidesstattliche Versicherung.';

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization,
        private readonly CaseService $cases,
        private readonly SecretCipher $cipher,
        private readonly NeutralNarrativeBuilder $builder,
        private readonly AuditLogger $audit
    ) {
    }

    public function detail(string $userId, string $caseId): array
    {
        $caseData = $this->m4Case($userId, $caseId);
        $observation = $this->latestObservation($caseId);
        $narrative = $this->latestNarrative($caseId);
        $report = $this->latestReport($caseId);

        if ($report !== null) {
            $report['snapshot'] = $this->decryptSnapshot((string) $report['snapshot_json']);
            unset($report['snapshot_json']);
            $report['current'] = $this->reportMatchesCurrentContext(
                $caseId,
                $report,
                $observation,
                $narrative,
                $caseData['evidence_package'] ?? null
            );

            $declaration = $this->fetchOne(
                'SELECT id, declaration_key, declaration_version, declaration_text, metadata_json,
                        accepted_by_user_id, accepted_at
                 FROM case_declarations WHERE witness_report_id = :report_id LIMIT 1',
                ['report_id' => $report['id']]
            );
            $report['declaration'] = $declaration;
        }

        return [
            'case_data' => $caseData,
            'observation' => $observation,
            'narrative' => $narrative,
            'report' => $report,
        ];
    }

    public function saveObservation(string $userId, string $caseId, array $input): array
    {
        $this->m4Case($userId, $caseId);

        $observationText = trim((string) ($input['observation_text'] ?? ''));
        $impactText = trim((string) ($input['impact_text'] ?? ''));
        $contextText = trim((string) ($input['context_text'] ?? ''));

        if (mb_strlen($observationText) < 10) {
            throw new \InvalidArgumentException('Die eigene Beobachtung muss mindestens 10 Zeichen enthalten.');
        }

        foreach ([$observationText, $impactText, $contextText] as $value) {
            if (mb_strlen($value) > 10000) {
                throw new \InvalidArgumentException('Ein Freitext ist zu lang.');
            }
        }

        $id = Uuid::v4();
        $version = $this->nextVersion('case_observation_statements', 'version_no', $caseId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO case_observation_statements
             (id, case_id, version_no, observation_text, impact_text, context_text,
              created_by_user_id, created_at)
             VALUES
             (:id, :case_id, :version_no, :observation_text, :impact_text, :context_text,
              :user_id, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'id' => $id,
            'case_id' => $caseId,
            'version_no' => $version,
            'observation_text' => $this->cipher->encrypt($observationText),
            'impact_text' => $impactText === '' ? null : $this->cipher->encrypt($impactText),
            'context_text' => $contextText === '' ? null : $this->cipher->encrypt($contextText),
            'user_id' => $userId,
        ]);

        $this->audit->log('CASE_OBSERVATION_STATEMENT_VERSIONED', 'case', $caseId, 'USER', $userId, [
            'statement_id' => $id,
            'version_no' => $version,
        ]);

        return $this->latestObservation($caseId)
            ?? throw new \RuntimeException('Observation version could not be loaded.');
    }

    public function generateNarrative(string $userId, string $caseId): array
    {
        $caseData = $this->m4Case($userId, $caseId);
        $observation = $this->latestObservation($caseId);

        if ($observation === null) {
            throw new \DomainException('Zuerst muss eine eigene Beobachtung gespeichert werden.');
        }

        $text = $this->builder->build($caseData, $observation);

        return $this->insertNarrative(
            $userId,
            $caseId,
            (string) $observation['id'],
            $text,
            $text,
            NeutralNarrativeBuilder::VERSION
        );
    }

    public function saveNarrative(string $userId, string $caseId, string $text): array
    {
        $this->m4Case($userId, $caseId);
        $observation = $this->latestObservation($caseId);
        $latest = $this->latestNarrative($caseId);

        if ($observation === null || $latest === null) {
            throw new \DomainException('Zuerst muss ein neutraler Beschreibungstext erzeugt werden.');
        }

        if ((string) $latest['observation_statement_id'] !== (string) $observation['id']) {
            throw new \DomainException('Die eigene Beobachtung wurde geändert. Bitte den neutralen Text neu erzeugen.');
        }

        $text = trim($text);
        if (mb_strlen($text) < 20 || mb_strlen($text) > 15000) {
            throw new \InvalidArgumentException('Der Beschreibungstext muss zwischen 20 und 15.000 Zeichen lang sein.');
        }

        return $this->insertNarrative(
            $userId,
            $caseId,
            (string) $observation['id'],
            (string) $latest['generated_text'],
            $text,
            (string) $latest['generator_version']
        );
    }

    public function createReport(string $userId, string $caseId): array
    {
        $caseData = $this->m4Case($userId, $caseId);
        $observation = $this->latestObservation($caseId);
        $narrative = $this->latestNarrative($caseId);
        $package = $caseData['evidence_package'] ?? null;

        if ($observation === null || $narrative === null || !is_array($package)) {
            throw new \DomainException('Beobachtung, Beschreibung und Beweismappe müssen vollständig vorliegen.');
        }

        if ((string) $narrative['observation_statement_id'] !== (string) $observation['id']) {
            throw new \DomainException('Der Beschreibungstext gehört nicht zur aktuellen Beobachtung.');
        }

        $snapshot = $this->buildSnapshot($caseData, $observation, $narrative, $package);
        $snapshotJson = json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $snapshotHash = hash('sha256', $snapshotJson);
        $id = Uuid::v4();
        $version = $this->nextVersion('witness_reports', 'version_no', $caseId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO witness_reports
             (id, case_id, version_no, observation_statement_id, narrative_id, evidence_package_id,
              snapshot_json, snapshot_sha256, confirmed_by_user_id, confirmed_at, created_at)
             VALUES
             (:id, :case_id, :version_no, :observation_id, :narrative_id, :package_id,
              :snapshot, :snapshot_sha256, NULL, NULL, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'id' => $id,
            'case_id' => $caseId,
            'version_no' => $version,
            'observation_id' => $observation['id'],
            'narrative_id' => $narrative['id'],
            'package_id' => $package['id'],
            'snapshot' => $this->cipher->encrypt($snapshotJson),
            'snapshot_sha256' => $snapshotHash,
        ]);

        $this->audit->log('WITNESS_REPORT_CREATED', 'case', $caseId, 'USER', $userId, [
            'report_id' => $id,
            'version_no' => $version,
            'snapshot_sha256' => $snapshotHash,
        ]);

        return $this->latestReportDecoded($caseId)
            ?? throw new \RuntimeException('Witness report could not be loaded.');
    }

    public function confirmReport(
        string $userId,
        string $reportId,
        bool $declarationAccepted,
        array $auditMetadata = []
    ): array {
        if (!$declarationAccepted) {
            throw new \InvalidArgumentException('Die elektronische Erklärung muss bestätigt werden.');
        }

        $report = $this->fetchOne(
            'SELECT wr.*, c.user_id, c.status AS case_status
             FROM witness_reports wr
             INNER JOIN cases c ON c.id = wr.case_id
             WHERE wr.id = :id LIMIT 1',
            ['id' => $reportId]
        );

        if ($report === null) {
            throw new \DomainException('Zeugenbericht nicht gefunden.');
        }

        $this->authorization->authorize($userId, 'case.edit_own', (string) $report['user_id']);

        if ($report['case_status'] !== CaseStatus::READY_FOR_REVIEW) {
            throw new \DomainException('Der Vorgang ist nicht im finalen Review.');
        }

        $caseData = $this->m4Case($userId, (string) $report['case_id']);
        $observation = $this->latestObservation((string) $report['case_id']);
        $narrative = $this->latestNarrative((string) $report['case_id']);

        if (!$this->reportMatchesCurrentContext(
            (string) $report['case_id'],
            $report,
            $observation,
            $narrative,
            $caseData['evidence_package'] ?? null
        )) {
            throw new \DomainException('Der Zeugenbericht ist nicht mehr aktuell. Bitte neu erzeugen.');
        }

        $snapshotJson = $this->cipher->decrypt((string) $report['snapshot_json']);
        if (!hash_equals((string) $report['snapshot_sha256'], hash('sha256', $snapshotJson))) {
            throw new \RuntimeException('Integrität des Zeugenbericht-Snapshots ist verletzt.');
        }

        try {
            $this->pdo->beginTransaction();

            $update = $this->pdo->prepare(
                'UPDATE witness_reports
                 SET confirmed_by_user_id = :user_id, confirmed_at = UTC_TIMESTAMP()
                 WHERE id = :id AND confirmed_at IS NULL'
            );
            $update->execute(['user_id' => $userId, 'id' => $reportId]);

            $existing = $this->pdo->prepare(
                'SELECT id FROM case_declarations WHERE witness_report_id = :report_id LIMIT 1'
            );
            $existing->execute(['report_id' => $reportId]);

            if ($existing->fetchColumn() === false) {
                $declaration = $this->pdo->prepare(
                    'INSERT INTO case_declarations
                     (id, case_id, witness_report_id, declaration_key, declaration_version,
                      declaration_text, metadata_json, accepted_by_user_id, accepted_at)
                     VALUES
                     (:id, :case_id, :report_id, :declaration_key, :declaration_version,
                      :declaration_text, :metadata, :user_id, UTC_TIMESTAMP())'
                );
                $declaration->execute([
                    'id' => Uuid::v4(),
                    'case_id' => $report['case_id'],
                    'report_id' => $reportId,
                    'declaration_key' => self::DECLARATION_KEY,
                    'declaration_version' => self::DECLARATION_VERSION,
                    'declaration_text' => self::DECLARATION_TEXT,
                    'metadata' => $auditMetadata === [] ? null : json_encode(
                        $auditMetadata,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    ),
                    'user_id' => $userId,
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log('WITNESS_REPORT_CONFIRMED', 'case', (string) $report['case_id'], 'USER', $userId, [
            'report_id' => $reportId,
            'version_no' => (int) $report['version_no'],
            'snapshot_sha256' => (string) $report['snapshot_sha256'],
        ]);

        return $this->latestReportDecoded((string) $report['case_id'])
            ?? throw new \RuntimeException('Confirmed witness report could not be loaded.');
    }

    public function currentConfirmedReport(string $userId, string $caseId): ?array
    {
        $caseData = $this->m4Case($userId, $caseId);
        $observation = $this->latestObservation($caseId);
        $narrative = $this->latestNarrative($caseId);
        $report = $this->latestReport($caseId);

        if (
            $report === null
            || $report['confirmed_at'] === null
            || !$this->reportMatchesCurrentContext(
                $caseId,
                $report,
                $observation,
                $narrative,
                $caseData['evidence_package'] ?? null
            )
        ) {
            return null;
        }

        $declaration = $this->fetchOne(
            'SELECT id, accepted_at FROM case_declarations WHERE witness_report_id = :id LIMIT 1',
            ['id' => $report['id']]
        );

        if ($declaration === null) {
            return null;
        }

        $snapshotJson = $this->cipher->decrypt((string) $report['snapshot_json']);
        if (!hash_equals((string) $report['snapshot_sha256'], hash('sha256', $snapshotJson))) {
            throw new \RuntimeException('Integrität des aktuellen Zeugenbericht-Snapshots ist verletzt.');
        }

        $decoded = json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Zeugenbericht-Snapshot ist ungültig.');
        }

        $report['snapshot'] = $decoded;
        unset($report['snapshot_json']);
        $report['declaration'] = $declaration;

        return $report;
    }

    private function insertNarrative(
        string $userId,
        string $caseId,
        string $observationId,
        string $generatedText,
        string $finalText,
        string $generatorVersion
    ): array {
        $id = Uuid::v4();
        $version = $this->nextVersion('case_narratives', 'version_no', $caseId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO case_narratives
             (id, case_id, version_no, observation_statement_id, generated_text, final_text,
              generator_version, created_by_user_id, created_at)
             VALUES
             (:id, :case_id, :version_no, :observation_id, :generated_text, :final_text,
              :generator_version, :user_id, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'id' => $id,
            'case_id' => $caseId,
            'version_no' => $version,
            'observation_id' => $observationId,
            'generated_text' => $this->cipher->encrypt($generatedText),
            'final_text' => $this->cipher->encrypt($finalText),
            'generator_version' => $generatorVersion,
            'user_id' => $userId,
        ]);

        $this->audit->log('CASE_NARRATIVE_VERSIONED', 'case', $caseId, 'USER', $userId, [
            'narrative_id' => $id,
            'version_no' => $version,
            'generator_version' => $generatorVersion,
        ]);

        return $this->latestNarrative($caseId)
            ?? throw new \RuntimeException('Narrative version could not be loaded.');
    }

    private function buildSnapshot(
        array $caseData,
        array $observation,
        array $narrative,
        array $package
    ): array {
        $case = $caseData['case'];
        $vehicle = $caseData['vehicle'];
        $location = $caseData['location'];
        $offense = $caseData['offenses'][0] ?? null;

        return [
            'schema_version' => 1,
            'case' => [
                'id' => (string) $case['id'],
                'public_number' => (string) $case['public_number'],
                'observed_from' => $case['observed_from'],
                'observed_until' => $case['observed_until'],
                'obstruction' => (bool) $case['obstruction'],
                'endangerment' => (bool) $case['endangerment'],
                'damage' => (bool) $case['damage'],
            ],
            'vehicle' => [
                'license_plate' => $vehicle['license_plate'] ?? null,
                'vehicle_type' => $vehicle['vehicle_type'] ?? null,
                'color' => $vehicle['color'] ?? null,
                'make' => $vehicle['make'] ?? null,
                'model' => $vehicle['model'] ?? null,
            ],
            'location' => [
                'latitude' => $location['latitude'] ?? null,
                'longitude' => $location['longitude'] ?? null,
                'street' => $location['street'] ?? null,
                'house_number' => $location['house_number'] ?? null,
                'postal_code' => $location['postal_code'] ?? null,
                'city' => $location['city'] ?? null,
                'traffic_space_type' => $location['traffic_space_type'] ?? null,
                'access_type' => $location['access_type'] ?? null,
            ],
            'selected_offense' => is_array($offense) ? [
                'offense_version_id' => $offense['offense_version_id'] ?? null,
                'stable_key' => $offense['stable_key'] ?? null,
                'title' => $offense['title'] ?? null,
                'category' => $offense['category'] ?? null,
                'user_confirmed' => (bool) ($offense['user_confirmed'] ?? false),
            ] : null,
            'own_observation' => [
                'id' => (string) $observation['id'],
                'version_no' => (int) $observation['version_no'],
                'observation_text' => (string) $observation['observation_text'],
                'impact_text' => $observation['impact_text'],
                'context_text' => $observation['context_text'],
            ],
            'narrative' => [
                'id' => (string) $narrative['id'],
                'version_no' => (int) $narrative['version_no'],
                'text' => (string) $narrative['final_text'],
                'generator_version' => (string) $narrative['generator_version'],
            ],
            'evidence_package' => [
                'id' => (string) $package['id'],
                'version_no' => (int) $package['version_no'],
                'manifest_sha256' => (string) $package['manifest_sha256'],
            ],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function m4Case(string $userId, string $caseId): array
    {
        $caseData = $this->cases->findOwned($userId, $caseId);

        if ($caseData === null) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        $this->authorization->authorize(
            $userId,
            'case.edit_own',
            (string) $caseData['case']['user_id']
        );

        if (
            (string) $caseData['case']['status'] !== CaseStatus::READY_FOR_REVIEW
            || !is_array($caseData['evidence_package'] ?? null)
        ) {
            throw new \DomainException('M4 ist erst nach eingefrorener Beweismappe verfügbar.');
        }

        return $caseData;
    }

    private function latestObservation(string $caseId): ?array
    {
        $row = $this->fetchOne(
            'SELECT * FROM case_observation_statements
             WHERE case_id = :case_id ORDER BY version_no DESC LIMIT 1',
            ['case_id' => $caseId]
        );

        if ($row === null) {
            return null;
        }

        $row['observation_text'] = $this->cipher->decrypt((string) $row['observation_text']);
        $row['impact_text'] = $row['impact_text'] === null ? null : $this->cipher->decrypt((string) $row['impact_text']);
        $row['context_text'] = $row['context_text'] === null ? null : $this->cipher->decrypt((string) $row['context_text']);

        return $row;
    }

    private function latestNarrative(string $caseId): ?array
    {
        $row = $this->fetchOne(
            'SELECT * FROM case_narratives
             WHERE case_id = :case_id ORDER BY version_no DESC LIMIT 1',
            ['case_id' => $caseId]
        );

        if ($row === null) {
            return null;
        }

        $row['generated_text'] = $this->cipher->decrypt((string) $row['generated_text']);
        $row['final_text'] = $this->cipher->decrypt((string) $row['final_text']);

        return $row;
    }

    private function latestReport(string $caseId): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM witness_reports
             WHERE case_id = :case_id ORDER BY version_no DESC LIMIT 1',
            ['case_id' => $caseId]
        );
    }

    private function latestReportDecoded(string $caseId): ?array
    {
        $report = $this->latestReport($caseId);

        if ($report === null) {
            return null;
        }

        $report['snapshot'] = $this->decryptSnapshot((string) $report['snapshot_json']);
        unset($report['snapshot_json']);

        return $report;
    }

    private function decryptSnapshot(string $encrypted): array
    {
        $json = $this->cipher->decrypt($encrypted);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Witness report snapshot is invalid.');
        }

        return $decoded;
    }

    private function reportMatchesCurrentContext(
        string $caseId,
        array $report,
        ?array $observation,
        ?array $narrative,
        mixed $package
    ): bool {
        return $observation !== null
            && $narrative !== null
            && is_array($package)
            && (string) $report['case_id'] === $caseId
            && (string) $report['observation_statement_id'] === (string) $observation['id']
            && (string) $report['narrative_id'] === (string) $narrative['id']
            && (string) $report['evidence_package_id'] === (string) $package['id'];
    }

    private function nextVersion(string $table, string $column, string $caseId): int
    {
        $allowed = [
            'case_observation_statements' => 'version_no',
            'case_narratives' => 'version_no',
            'witness_reports' => 'version_no',
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

    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
