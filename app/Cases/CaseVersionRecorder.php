<?php

declare(strict_types=1);

namespace MeldeVerkehr\Cases;

use MeldeVerkehr\Support\Uuid;
use PDO;
use PDOException;
use RuntimeException;

final class CaseVersionRecorder
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(
        string $caseId,
        string $type,
        ?string $actorId = null,
        ?string $reason = null
    ): array {
        $snapshot = $this->snapshot($caseId);
        $normalized = $this->normalize($snapshot);
        $json = json_encode(
            $normalized,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $hash = hash('sha256', $json);

        $ownsTransaction = !$this->pdo->inTransaction();

        for ($attempt = 0; $attempt < ($ownsTransaction ? 2 : 1); $attempt++) {
            try {
                if ($ownsTransaction) {
                    $this->pdo->beginTransaction();
                }

                $stmt = $this->pdo->prepare(
                    'SELECT COALESCE(MAX(version_no), 0) + 1
                     FROM case_versions
                     WHERE case_id = :case_id'
                );
                $stmt->execute(['case_id' => $caseId]);
                $versionNo = (int) $stmt->fetchColumn();

                $id = Uuid::v4();
                $insert = $this->pdo->prepare(
                    'INSERT INTO case_versions
                     (id, case_id, version_no, version_type, reason, snapshot_json, snapshot_sha256, created_by, created_at)
                     VALUES
                     (:id, :case_id, :version_no, :version_type, :reason, :snapshot_json, :snapshot_sha256, :created_by, UTC_TIMESTAMP())'
                );
                $insert->execute([
                    'id' => $id,
                    'case_id' => $caseId,
                    'version_no' => $versionNo,
                    'version_type' => mb_substr(trim($type), 0, 40),
                    'reason' => $reason === null ? null : mb_substr(trim($reason), 0, 500),
                    'snapshot_json' => $json,
                    'snapshot_sha256' => $hash,
                    'created_by' => $actorId,
                ]);

                if ($ownsTransaction) {
                    $this->pdo->commit();
                }

                return [
                    'id' => $id,
                    'case_id' => $caseId,
                    'version_no' => $versionNo,
                    'version_type' => $type,
                    'snapshot_sha256' => $hash,
                ];
            } catch (PDOException $e) {
                if ($ownsTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                if ($ownsTransaction && $attempt === 0 && $e->getCode() === '23000') {
                    continue;
                }

                throw $e;
            }
        }

        throw new RuntimeException('Vorgangsversion konnte nicht erzeugt werden.');
    }

    public function snapshot(string $caseId): array
    {
        $case = $this->fetchOne(
            'SELECT * FROM cases WHERE id = :case_id LIMIT 1',
            ['case_id' => $caseId]
        );

        if ($case === null) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        return [
            'schema' => 1,
            'case' => $case,
            'vehicle' => $this->fetchOne(
                'SELECT * FROM vehicles WHERE case_id = :case_id LIMIT 1',
                ['case_id' => $caseId]
            ),
            'location' => $this->fetchOne(
                'SELECT * FROM locations WHERE case_id = :case_id LIMIT 1',
                ['case_id' => $caseId]
            ),
            'offenses' => $this->fetchAll(
                'SELECT co.id, co.offense_version_id, co.is_primary, co.user_confirmed, co.ai_suggested, co.confidence,
                        ov.version, ov.code, ov.title, ov.description, ov.legal_reference,
                        o.stable_key, o.category_key
                 FROM case_offenses co
                 INNER JOIN offense_versions ov ON ov.id = co.offense_version_id
                 INNER JOIN offenses o ON o.id = ov.offense_id
                 WHERE co.case_id = :case_id
                 ORDER BY co.is_primary DESC, co.id ASC',
                ['case_id' => $caseId]
            ),
            'evidence_packages' => $this->fetchAll(
                'SELECT id, version_no, status, manifest_sha256, frozen_at, created_at
                 FROM evidence_packages
                 WHERE case_id = :case_id
                 ORDER BY version_no ASC',
                ['case_id' => $caseId]
            ),
            'amendments' => $this->fetchAll(
                'SELECT id, amendment_no, title, content, created_by, created_at
                 FROM case_amendments
                 WHERE case_id = :case_id
                 ORDER BY amendment_no ASC',
                ['case_id' => $caseId]
            ),
            'corrections' => $this->fetchAll(
                'SELECT id, previous_status, category, original_value, corrected_value, reason, status,
                        requested_by, requested_at, completed_by, completed_at, completion_note
                 FROM case_correction_requests
                 WHERE case_id = :case_id
                 ORDER BY requested_at ASC',
                ['case_id' => $caseId]
            ),
            'withdrawals' => $this->fetchAll(
                'SELECT id, previous_status, reason, status, requested_by, requested_at,
                        completed_by, completed_at, completion_note
                 FROM case_withdrawals
                 WHERE case_id = :case_id
                 ORDER BY requested_at ASC',
                ['case_id' => $caseId]
            ),
            'status_history' => $this->fetchAll(
                'SELECT old_status, new_status, source, actor_id, reason, created_at
                 FROM case_status_history
                 WHERE case_id = :case_id
                 ORDER BY id ASC',
                ['case_id' => $caseId]
            ),
        ];
    }

    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function fetchAll(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return [];
        }

        $keys = array_keys($value);
        $isList = $keys === range(0, count($value) - 1);

        if ($isList) {
            return array_map(fn(mixed $item): mixed => $this->normalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
