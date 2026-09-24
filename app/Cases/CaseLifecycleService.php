<?php

declare(strict_types=1);

namespace MeldeVerkehr\Cases;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class CaseLifecycleService
{
    private const CORRECTION_STATUSES = [
        CaseStatus::SENT,
        CaseStatus::DELIVERED,
        CaseStatus::DELIVERY_UNKNOWN,
        CaseStatus::DELIVERY_FAILED,
        CaseStatus::AUTHORITY_REPLY,
        CaseStatus::USER_ACTION_REQUIRED,
        CaseStatus::AUTHORITY_PROCESSING,
    ];

    private const WITHDRAWAL_STATUSES = [
        CaseStatus::SUBMISSION_PENDING,
        CaseStatus::SENT,
        CaseStatus::DELIVERED,
        CaseStatus::DELIVERY_UNKNOWN,
        CaseStatus::DELIVERY_FAILED,
        CaseStatus::AUTHORITY_REPLY,
        CaseStatus::USER_ACTION_REQUIRED,
        CaseStatus::AUTHORITY_PROCESSING,
        CaseStatus::CORRECTION_PENDING,
    ];

    private const CLOSE_STATUSES = [
        CaseStatus::DELIVERED,
        CaseStatus::AUTHORITY_REPLY,
        CaseStatus::USER_ACTION_REQUIRED,
        CaseStatus::AUTHORITY_PROCESSING,
    ];

    private const CORRECTION_CATEGORIES = [
        'VEHICLE',
        'LOCATION',
        'OBSERVATION',
        'OFFENSE',
        'EVIDENCE',
        'NARRATIVE',
        'PERSON_DATA',
        'OTHER',
    ];

    private const CLOSURE_REASONS = [
        'AUTHORITY_COMPLETED',
        'NO_FURTHER_ACTION',
        'RESOLVED',
        'WITHDRAWN',
        'OTHER',
    ];

    private readonly CaseVersionRecorder $versions;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $audit
    ) {
        $this->versions = new CaseVersionRecorder($pdo);
    }

    public function overview(string $userId, string $caseId): array
    {
        $case = $this->ownedCase($userId, $caseId, 'case.view_own');

        $versions = $this->fetchAll(
            'SELECT id, version_no, version_type, reason, snapshot_sha256, created_by, created_at, snapshot_json
             FROM case_versions
             WHERE case_id = :case_id
             ORDER BY version_no DESC',
            ['case_id' => $caseId]
        );

        foreach ($versions as &$version) {
            $json = (string) ($version['snapshot_json'] ?? '');
            $version['integrity_valid'] = $json !== ''
                && hash_equals((string) $version['snapshot_sha256'], hash('sha256', $json));
            unset($version['snapshot_json']);
        }
        unset($version);

        $closures = $this->fetchAll(
            'SELECT id, closure_no, closure_reason, closure_note, snapshot_version_id,
                    dossier_sha256, closed_by, closed_at, archived_at, dossier_json
             FROM case_closure_records
             WHERE case_id = :case_id
             ORDER BY closure_no DESC',
            ['case_id' => $caseId]
        );

        foreach ($closures as &$closure) {
            $json = (string) ($closure['dossier_json'] ?? '');
            $closure['integrity_valid'] = $json !== ''
                && hash_equals((string) $closure['dossier_sha256'], hash('sha256', $json));
            unset($closure['dossier_json']);
        }
        unset($closure);

        return [
            'case' => $case,
            'versions' => $versions,
            'amendments' => $this->fetchAll(
                'SELECT id, amendment_no, title, content, case_version_id, created_by, created_at
                 FROM case_amendments
                 WHERE case_id = :case_id
                 ORDER BY amendment_no DESC',
                ['case_id' => $caseId]
            ),
            'corrections' => $this->fetchAll(
                'SELECT id, category, original_value, corrected_value, reason, status, previous_status,
                        before_version_id, completed_version_id, requested_by, requested_at,
                        completed_by, completed_at, completion_note
                 FROM case_correction_requests
                 WHERE case_id = :case_id
                 ORDER BY requested_at DESC',
                ['case_id' => $caseId]
            ),
            'withdrawals' => $this->fetchAll(
                'SELECT id, reason, status, previous_status, before_version_id, closure_version_id,
                        requested_by, requested_at, completed_by, completed_at, completion_note
                 FROM case_withdrawals
                 WHERE case_id = :case_id
                 ORDER BY requested_at DESC',
                ['case_id' => $caseId]
            ),
            'closures' => $closures,
            'can_amend' => !in_array((string) $case['status'], [
                CaseStatus::CLOSED,
                CaseStatus::ARCHIVED,
                CaseStatus::DELETION_PENDING,
            ], true),
            'can_correct' => in_array((string) $case['status'], self::CORRECTION_STATUSES, true),
            'can_withdraw' => in_array((string) $case['status'], self::WITHDRAWAL_STATUSES, true),
            'can_close' => in_array((string) $case['status'], self::CLOSE_STATUSES, true),
            'can_archive' => (string) $case['status'] === CaseStatus::CLOSED,
            'correction_categories' => self::CORRECTION_CATEGORIES,
            'closure_reasons' => self::CLOSURE_REASONS,
        ];
    }

    public function closureDossier(string $userId, string $caseId, string $closureId): array
    {
        $case = $this->ownedCase($userId, $caseId, 'case.view_own');

        $row = $this->fetchOne(
            'SELECT id, closure_no, closure_reason, dossier_json, dossier_sha256, closed_at
             FROM case_closure_records
             WHERE id = :id AND case_id = :case_id LIMIT 1',
            ['id' => $closureId, 'case_id' => $caseId]
        );

        if ($row === null) {
            throw new \DomainException('Abschlussakte nicht gefunden.');
        }

        $json = (string) $row['dossier_json'];
        if ($json === '' || !hash_equals((string) $row['dossier_sha256'], hash('sha256', $json))) {
            throw new \DomainException('Integritätsprüfung der Abschlussakte ist fehlgeschlagen.');
        }

        return [
            'case' => $case,
            'closure' => $row,
            'json' => $json,
            'filename' => sprintf(
                '%s_Abschlussakte_%02d.json',
                preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $case['public_number']) ?: 'MeldeVerkehr',
                (int) $row['closure_no']
            ),
        ];
    }

    public function addAmendment(
        string $userId,
        string $caseId,
        string $title,
        string $content
    ): array {
        $case = $this->ownedCase($userId, $caseId, 'case.edit_own');

        if (in_array((string) $case['status'], [
            CaseStatus::CLOSED,
            CaseStatus::ARCHIVED,
            CaseStatus::DELETION_PENDING,
        ], true)) {
            throw new \DomainException('Zu einem abgeschlossenen oder archivierten Vorgang kann kein Nachtrag mehr angelegt werden.');
        }

        $title = trim($title);
        $content = trim($content);

        if ($title === '' || mb_strlen($title) > 190) {
            throw new \InvalidArgumentException('Der Titel des Nachtrags ist erforderlich und darf höchstens 190 Zeichen lang sein.');
        }
        if ($content === '' || mb_strlen($content) > 10000) {
            throw new \InvalidArgumentException('Der Nachtrag ist erforderlich und darf höchstens 10.000 Zeichen lang sein.');
        }

        try {
            $this->pdo->beginTransaction();

            $number = $this->nextNumber('case_amendments', 'amendment_no', $caseId);
            $id = Uuid::v4();

            $stmt = $this->pdo->prepare(
                'INSERT INTO case_amendments
                 (id, case_id, case_version_id, amendment_no, title, content, created_by, created_at)
                 VALUES (:id, :case_id, NULL, :amendment_no, :title, :content, :created_by, UTC_TIMESTAMP())'
            );
            $stmt->execute([
                'id' => $id,
                'case_id' => $caseId,
                'amendment_no' => $number,
                'title' => $title,
                'content' => $content,
                'created_by' => $userId,
            ]);

            $version = $this->versions->record(
                $caseId,
                'AMENDMENT',
                $userId,
                'Nachtrag #' . $number . ': ' . $title
            );

            $this->pdo->prepare(
                'UPDATE case_amendments SET case_version_id = :version_id WHERE id = :id'
            )->execute([
                'version_id' => $version['id'],
                'id' => $id,
            ]);

            $this->timeline($caseId, 'USER', 'CASE_AMENDMENT_ADDED', $userId, [
                'amendment_id' => $id,
                'amendment_no' => $number,
                'version_no' => $version['version_no'],
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log('CASE_AMENDMENT_ADDED', 'case', $caseId, 'USER', $userId, [
            'amendment_no' => $number,
            'version_no' => $version['version_no'],
        ]);

        return [
            'id' => $id,
            'amendment_no' => $number,
            'version' => $version,
        ];
    }

    public function requestCorrection(
        string $userId,
        string $caseId,
        string $category,
        ?string $originalValue,
        string $correctedValue,
        string $reason
    ): array {
        $case = $this->ownedCase($userId, $caseId, 'case.edit_own');
        $status = (string) $case['status'];

        if (!in_array($status, self::CORRECTION_STATUSES, true)) {
            throw new \DomainException('In diesem Vorgangsstatus kann keine Korrektur angefordert werden.');
        }

        $category = strtoupper(trim($category));
        if (!in_array($category, self::CORRECTION_CATEGORIES, true)) {
            throw new \InvalidArgumentException('Ungültiger Korrekturbereich.');
        }

        $originalValue = $this->nullableText($originalValue, 10000);
        $correctedValue = trim($correctedValue);
        $reason = trim($reason);

        if ($correctedValue === '' || mb_strlen($correctedValue) > 10000) {
            throw new \InvalidArgumentException('Die korrigierte Angabe ist erforderlich und darf höchstens 10.000 Zeichen lang sein.');
        }
        if ($reason === '' || mb_strlen($reason) > 10000) {
            throw new \InvalidArgumentException('Die Begründung ist erforderlich und darf höchstens 10.000 Zeichen lang sein.');
        }

        try {
            $this->pdo->beginTransaction();

            $before = $this->versions->record(
                $caseId,
                'PRE_CORRECTION',
                $userId,
                'Stand vor Korrekturantrag'
            );

            $id = Uuid::v4();
            $stmt = $this->pdo->prepare(
                'INSERT INTO case_correction_requests
                 (id, case_id, before_version_id, completed_version_id, previous_status, category,
                  original_value, corrected_value, reason, status, requested_by, requested_at,
                  completed_by, completed_at, completion_note)
                 VALUES
                 (:id, :case_id, :before_version_id, NULL, :previous_status, :category,
                  :original_value, :corrected_value, :reason, "OPEN", :requested_by, UTC_TIMESTAMP(),
                  NULL, NULL, NULL)'
            );
            $stmt->execute([
                'id' => $id,
                'case_id' => $caseId,
                'before_version_id' => $before['id'],
                'previous_status' => $status,
                'category' => $category,
                'original_value' => $originalValue,
                'corrected_value' => $correctedValue,
                'reason' => $reason,
                'requested_by' => $userId,
            ]);

            $this->transition($case, CaseStatus::CORRECTION_PENDING, $userId, 'Korrekturantrag angelegt');
            $this->timeline($caseId, 'USER', 'CASE_CORRECTION_REQUESTED', $userId, [
                'correction_id' => $id,
                'category' => $category,
                'before_version_no' => $before['version_no'],
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log('CASE_CORRECTION_REQUESTED', 'case', $caseId, 'USER', $userId, [
            'correction_id' => $id,
            'category' => $category,
            'before_version_no' => $before['version_no'],
        ]);

        return ['id' => $id, 'before_version' => $before];
    }

    public function completeCorrection(
        string $userId,
        string $caseId,
        string $correctionId,
        ?string $completionNote = null
    ): array {
        $case = $this->ownedCase($userId, $caseId, 'case.edit_own');

        if ((string) $case['status'] !== CaseStatus::CORRECTION_PENDING) {
            throw new \DomainException('Der Vorgang befindet sich nicht in einem offenen Korrekturworkflow.');
        }

        $correction = $this->fetchOne(
            'SELECT * FROM case_correction_requests
             WHERE id = :id AND case_id = :case_id LIMIT 1',
            ['id' => $correctionId, 'case_id' => $caseId]
        );

        if ($correction === null || (string) $correction['status'] !== 'OPEN') {
            throw new \DomainException('Offener Korrekturantrag nicht gefunden.');
        }

        $completionNote = $this->nullableText($completionNote, 10000);

        try {
            $this->pdo->beginTransaction();

            $this->transition(
                $case,
                CaseStatus::AUTHORITY_PROCESSING,
                $userId,
                'Korrektur dokumentiert und zur weiteren Bearbeitung übergeben'
            );

            $completed = $this->versions->record(
                $caseId,
                'CORRECTION_COMPLETED',
                $userId,
                'Korrekturworkflow abgeschlossen'
            );

            $stmt = $this->pdo->prepare(
                'UPDATE case_correction_requests
                 SET status = "COMPLETED", completed_version_id = :version_id,
                     completed_by = :completed_by, completed_at = UTC_TIMESTAMP(),
                     completion_note = :completion_note
                 WHERE id = :id AND case_id = :case_id AND status = "OPEN"'
            );
            $stmt->execute([
                'version_id' => $completed['id'],
                'completed_by' => $userId,
                'completion_note' => $completionNote,
                'id' => $correctionId,
                'case_id' => $caseId,
            ]);

            $this->timeline($caseId, 'USER', 'CASE_CORRECTION_COMPLETED', $userId, [
                'correction_id' => $correctionId,
                'version_no' => $completed['version_no'],
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log('CASE_CORRECTION_COMPLETED', 'case', $caseId, 'USER', $userId, [
            'correction_id' => $correctionId,
            'version_no' => $completed['version_no'],
        ]);

        return $completed;
    }

    public function requestWithdrawal(string $userId, string $caseId, string $reason): array
    {
        $case = $this->ownedCase($userId, $caseId, 'case.edit_own');
        $status = (string) $case['status'];

        if (!in_array($status, self::WITHDRAWAL_STATUSES, true)) {
            throw new \DomainException('In diesem Vorgangsstatus kann keine Rücknahme gestartet werden.');
        }

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 10000) {
            throw new \InvalidArgumentException('Die Rücknahmebegründung ist erforderlich und darf höchstens 10.000 Zeichen lang sein.');
        }

        try {
            $this->pdo->beginTransaction();

            $before = $this->versions->record(
                $caseId,
                'PRE_WITHDRAWAL',
                $userId,
                'Stand vor Rücknahmeantrag'
            );

            $id = Uuid::v4();
            $stmt = $this->pdo->prepare(
                'INSERT INTO case_withdrawals
                 (id, case_id, before_version_id, closure_version_id, previous_status, reason, status,
                  requested_by, requested_at, completed_by, completed_at, completion_note)
                 VALUES
                 (:id, :case_id, :before_version_id, NULL, :previous_status, :reason, "OPEN",
                  :requested_by, UTC_TIMESTAMP(), NULL, NULL, NULL)'
            );
            $stmt->execute([
                'id' => $id,
                'case_id' => $caseId,
                'before_version_id' => $before['id'],
                'previous_status' => $status,
                'reason' => $reason,
                'requested_by' => $userId,
            ]);

            $this->transition($case, CaseStatus::WITHDRAWAL_PENDING, $userId, 'Rücknahme angefordert');
            $this->timeline($caseId, 'USER', 'CASE_WITHDRAWAL_REQUESTED', $userId, [
                'withdrawal_id' => $id,
                'before_version_no' => $before['version_no'],
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log('CASE_WITHDRAWAL_REQUESTED', 'case', $caseId, 'USER', $userId, [
            'withdrawal_id' => $id,
            'before_version_no' => $before['version_no'],
        ]);

        return ['id' => $id, 'before_version' => $before];
    }

    public function completeWithdrawal(
        string $userId,
        string $caseId,
        string $withdrawalId,
        ?string $completionNote = null
    ): array {
        $case = $this->ownedCase($userId, $caseId, 'case.edit_own');

        if ((string) $case['status'] !== CaseStatus::WITHDRAWAL_PENDING) {
            throw new \DomainException('Der Vorgang befindet sich nicht in einem offenen Rücknahmeworkflow.');
        }

        $withdrawal = $this->fetchOne(
            'SELECT * FROM case_withdrawals
             WHERE id = :id AND case_id = :case_id LIMIT 1',
            ['id' => $withdrawalId, 'case_id' => $caseId]
        );

        if ($withdrawal === null || (string) $withdrawal['status'] !== 'OPEN') {
            throw new \DomainException('Offene Rücknahme nicht gefunden.');
        }

        $completionNote = $this->nullableText($completionNote, 10000);

        try {
            $this->pdo->beginTransaction();

            $this->transition($case, CaseStatus::CLOSED, $userId, 'Rücknahme abgeschlossen');

            $closure = $this->createClosureRecord(
                $caseId,
                $userId,
                'WITHDRAWN',
                $completionNote ?? (string) $withdrawal['reason']
            );

            $stmt = $this->pdo->prepare(
                'UPDATE case_withdrawals
                 SET status = "COMPLETED", closure_version_id = :version_id,
                     completed_by = :completed_by, completed_at = UTC_TIMESTAMP(),
                     completion_note = :completion_note
                 WHERE id = :id AND case_id = :case_id AND status = "OPEN"'
            );
            $stmt->execute([
                'version_id' => $closure['version']['id'],
                'completed_by' => $userId,
                'completion_note' => $completionNote,
                'id' => $withdrawalId,
                'case_id' => $caseId,
            ]);

            $this->timeline($caseId, 'USER', 'CASE_WITHDRAWAL_COMPLETED', $userId, [
                'withdrawal_id' => $withdrawalId,
                'closure_no' => $closure['closure_no'],
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log('CASE_WITHDRAWAL_COMPLETED', 'case', $caseId, 'USER', $userId, [
            'withdrawal_id' => $withdrawalId,
            'closure_no' => $closure['closure_no'],
        ]);

        return $closure;
    }

    public function closeCase(
        string $userId,
        string $caseId,
        string $reason,
        ?string $note = null
    ): array {
        $case = $this->ownedCase($userId, $caseId, 'case.edit_own');

        if (!in_array((string) $case['status'], self::CLOSE_STATUSES, true)) {
            throw new \DomainException('Der Vorgang kann in diesem Status nicht abgeschlossen werden.');
        }

        $reason = strtoupper(trim($reason));
        if (!in_array($reason, self::CLOSURE_REASONS, true) || $reason === 'WITHDRAWN') {
            throw new \InvalidArgumentException('Ungültiger Abschlussgrund.');
        }

        $note = $this->nullableText($note, 10000);

        try {
            $this->pdo->beginTransaction();

            $this->transition($case, CaseStatus::CLOSED, $userId, 'Vorgang abgeschlossen');
            $closure = $this->createClosureRecord($caseId, $userId, $reason, $note);

            $this->timeline($caseId, 'USER', 'CASE_CLOSED_WITH_DOSSIER', $userId, [
                'closure_no' => $closure['closure_no'],
                'reason' => $reason,
                'version_no' => $closure['version']['version_no'],
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log('CASE_CLOSED_WITH_DOSSIER', 'case', $caseId, 'USER', $userId, [
            'closure_no' => $closure['closure_no'],
            'reason' => $reason,
            'version_no' => $closure['version']['version_no'],
        ]);

        return $closure;
    }

    public function archiveCase(string $userId, string $caseId): void
    {
        $case = $this->ownedCase($userId, $caseId, 'case.edit_own');

        if ((string) $case['status'] !== CaseStatus::CLOSED) {
            throw new \DomainException('Nur abgeschlossene Vorgänge können archiviert werden.');
        }

        try {
            $this->pdo->beginTransaction();

            $this->transition($case, CaseStatus::ARCHIVED, $userId, 'Vorgang archiviert');
            $this->pdo->prepare(
                'UPDATE case_closure_records
                 SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP())
                 WHERE case_id = :case_id'
            )->execute(['case_id' => $caseId]);

            $version = $this->versions->record(
                $caseId,
                'ARCHIVED',
                $userId,
                'Archivstand'
            );

            $this->timeline($caseId, 'USER', 'CASE_ARCHIVED', $userId, [
                'version_no' => $version['version_no'],
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log('CASE_ARCHIVED', 'case', $caseId, 'USER', $userId, [
            'version_no' => $version['version_no'],
        ]);
    }

    private function createClosureRecord(
        string $caseId,
        string $userId,
        string $reason,
        ?string $note
    ): array {
        $version = $this->versions->record(
            $caseId,
            'CLOSURE',
            $userId,
            'Abschlussakte: ' . $reason
        );

        $closureNo = $this->nextNumber('case_closure_records', 'closure_no', $caseId);
        $dossier = [
            'schema' => 1,
            'closure' => [
                'closure_no' => $closureNo,
                'reason' => $reason,
                'note' => $note,
                'closed_by' => $userId,
            ],
            'snapshot_version' => $version,
            'snapshot' => $this->versions->snapshot($caseId),
            'versions' => $this->fetchAll(
                'SELECT id, version_no, version_type, reason, snapshot_sha256, created_by, created_at
                 FROM case_versions
                 WHERE case_id = :case_id
                 ORDER BY version_no ASC',
                ['case_id' => $caseId]
            ),
            'timeline' => $this->fetchAll(
                'SELECT event_type, event_key, actor_id, payload_json, created_at
                 FROM case_timeline
                 WHERE case_id = :case_id
                 ORDER BY id ASC',
                ['case_id' => $caseId]
            ),
        ];

        $json = json_encode(
            $this->normalize($dossier),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $hash = hash('sha256', $json);
        $id = Uuid::v4();

        $stmt = $this->pdo->prepare(
            'INSERT INTO case_closure_records
             (id, case_id, closure_no, closure_reason, closure_note, snapshot_version_id,
              dossier_json, dossier_sha256, closed_by, closed_at, archived_at)
             VALUES
             (:id, :case_id, :closure_no, :closure_reason, :closure_note, :snapshot_version_id,
              :dossier_json, :dossier_sha256, :closed_by, UTC_TIMESTAMP(), NULL)'
        );
        $stmt->execute([
            'id' => $id,
            'case_id' => $caseId,
            'closure_no' => $closureNo,
            'closure_reason' => $reason,
            'closure_note' => $note,
            'snapshot_version_id' => $version['id'],
            'dossier_json' => $json,
            'dossier_sha256' => $hash,
            'closed_by' => $userId,
        ]);

        return [
            'id' => $id,
            'closure_no' => $closureNo,
            'dossier_sha256' => $hash,
            'version' => $version,
        ];
    }

    private function transition(array $case, string $to, string $userId, string $reason): void
    {
        $from = (string) $case['status'];
        (new CaseStatusMachine())->assert($from, $to);

        $sql = 'UPDATE cases SET status = :status, updated_at = UTC_TIMESTAMP()';
        if ($to === CaseStatus::CLOSED) {
            $sql .= ', closed_at = UTC_TIMESTAMP()';
        }
        $sql .= ' WHERE id = :id';

        $this->pdo->prepare($sql)->execute([
            'status' => $to,
            'id' => $case['id'],
        ]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO case_status_history
             (case_id, old_status, new_status, source, actor_id, reason, created_at)
             VALUES (:case_id, :old_status, :new_status, "USER", :actor_id, :reason, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'case_id' => $case['id'],
            'old_status' => $from,
            'new_status' => $to,
            'actor_id' => $userId,
            'reason' => mb_substr($reason, 0, 255),
        ]);

        $this->timeline((string) $case['id'], 'USER', 'STATUS_CHANGED', $userId, [
            'from' => $from,
            'to' => $to,
            'reason' => $reason,
        ]);
    }

    private function ownedCase(string $userId, string $caseId, string $permission): array
    {
        $case = $this->fetchOne(
            'SELECT * FROM cases WHERE id = :id LIMIT 1',
            ['id' => $caseId]
        );

        if ($case === null) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        $this->authorization->authorize($userId, $permission, (string) $case['user_id']);

        return $case;
    }

    private function nextNumber(string $table, string $column, string $caseId): int
    {
        if (!in_array($table, ['case_amendments', 'case_closure_records'], true)) {
            throw new \LogicException('Unsupported lifecycle sequence table.');
        }
        if (!in_array($column, ['amendment_no', 'closure_no'], true)) {
            throw new \LogicException('Unsupported lifecycle sequence column.');
        }

        $stmt = $this->pdo->prepare(
            sprintf(
                'SELECT COALESCE(MAX(%s), 0) + 1 FROM %s WHERE case_id = :case_id',
                $column,
                $table
            )
        );
        $stmt->execute(['case_id' => $caseId]);

        return (int) $stmt->fetchColumn();
    }

    private function timeline(
        string $caseId,
        string $eventType,
        string $eventKey,
        ?string $actorId,
        array $payload = []
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO case_timeline
             (case_id, event_type, event_key, actor_id, payload_json, created_at)
             VALUES (:case_id, :event_type, :event_key, :actor_id, :payload_json, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'case_id' => $caseId,
            'event_type' => $eventType,
            'event_key' => $eventKey,
            'actor_id' => $actorId,
            'payload_json' => $payload === []
                ? null
                : json_encode(
                    $payload,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
        ]);
    }

    private function nullableText(?string $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
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
