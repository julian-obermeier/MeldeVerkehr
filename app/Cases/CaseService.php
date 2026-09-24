<?php

declare(strict_types=1);

namespace MeldeVerkehr\Cases;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class CaseService
{
    private const VEHICLE_TYPES = ['PKW','MOTORRAD','TRANSPORTER','LKW','BUS','ANHÄNGER','WOHNMOBIL','SONSTIGES'];
    private const TRAFFIC_SPACES = ['ROADWAY','SIDEWALK','BIKE_LANE','BIKE_PATH','SHOULDER','PARKING_AREA','PEDESTRIAN_ZONE','PRIVATE_PROPERTY','UNKNOWN'];
    private const ACCESS_TYPES = ['PUBLIC','PRIVATE','UNCLEAR'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization,
        private readonly SecretCipher $cipher,
        private readonly string $searchKey,
        private readonly AuditLogger $audit,
        private readonly string $timezone = 'Europe/Berlin'
    ) {
    }

    public function createDraft(string $userId): array
    {
        $this->authorization->authorize($userId, 'case.create', $userId);

        try {
            $this->pdo->beginTransaction();

            $id = Uuid::v4();
            $publicNumber = (new CaseNumberService($this->pdo))->next();
            $status = CaseStatus::DRAFT;

            $stmt = $this->pdo->prepare(
                'INSERT INTO cases
                 (id, public_number, user_id, status, observed_from, observed_until, obstruction, endangerment, damage,
                  submitted_at, closed_at, created_at, updated_at)
                 VALUES
                 (:id, :public_number, :user_id, :status, NULL, NULL, 0, 0, 0, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $stmt->execute([
                'id' => $id,
                'public_number' => $publicNumber,
                'user_id' => $userId,
                'status' => $status,
            ]);

            $this->recordStatus($id, null, $status, 'USER', $userId, 'Vorgang angelegt');
            $this->timeline($id, 'USER', 'CASE_CREATED', $userId, ['public_number' => $publicNumber]);

            $this->pdo->commit();

            $this->audit->log('CASE_CREATED', 'case', $id, 'USER', $userId, [
                'public_number' => $publicNumber,
            ]);

            return $this->findOwned($userId, $id) ?? throw new \RuntimeException('Created case could not be loaded.');
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function listOwned(string $userId, ?string $status = null, ?string $search = null): array
    {
        $sql = 'SELECT c.id, c.public_number, c.status, c.created_at, c.updated_at,
                       v.vehicle_type, v.license_plate_encrypted,
                       l.street, l.house_number, l.city
                FROM cases c
                LEFT JOIN vehicles v ON v.case_id = c.id
                LEFT JOIN locations l ON l.case_id = c.id
                WHERE c.user_id = :user_id';

        $params = ['user_id' => $userId];

        if ($status !== null && in_array($status, CaseStatus::all(), true)) {
            $sql .= ' AND c.status = :status';
            $params['status'] = $status;
        }

        $search = trim((string) ($search ?? ''));
        if ($search !== '') {
            $normalizedPlate = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($search, 'UTF-8')) ?? '';
            $sql .= ' AND (
                c.public_number LIKE :search_number
                OR l.street LIKE :search_street
                OR l.city LIKE :search_city
                OR v.license_plate_hash = :plate_hash
            )';
            $like = '%' . $search . '%';
            $params['search_number'] = $like;
            $params['search_street'] = $like;
            $params['search_city'] = $like;
            $params['plate_hash'] = hash_hmac('sha256', $normalizedPlate, $this->searchKey);
        }

        $sql .= ' ORDER BY c.updated_at DESC LIMIT 100';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['license_plate'] = $this->decryptNullable($row['license_plate_encrypted'] ?? null);
            unset($row['license_plate_encrypted']);
        }

        return $rows;
    }

    public function findOwned(string $userId, string $caseId): ?array
    {
        $case = $this->findRaw($caseId);

        if ($case === null) {
            return null;
        }

        $this->authorization->authorize($userId, 'case.view_own', (string) $case['user_id']);
        $case['observed_from_local'] = $this->toLocalInput($case['observed_from'] ?? null);
        $case['observed_until_local'] = $this->toLocalInput($case['observed_until'] ?? null);
        $case['observation_duration_seconds'] = $this->durationSeconds(
            $case['observed_from'] ?? null,
            $case['observed_until'] ?? null
        );

        $vehicle = $this->fetchOne('SELECT * FROM vehicles WHERE case_id = :case_id LIMIT 1', ['case_id' => $caseId]);
        if ($vehicle !== null) {
            $vehicle['license_plate'] = $this->decryptNullable($vehicle['license_plate_encrypted'] ?? null);
            unset($vehicle['license_plate_encrypted']);
        }

        $location = $this->fetchOne('SELECT * FROM locations WHERE case_id = :case_id LIMIT 1', ['case_id' => $caseId]);

        $offenses = $this->fetchAll(
            'SELECT co.is_primary, co.user_confirmed, co.ai_suggested, co.confidence,
                    ov.id AS offense_version_id, ov.version, ov.code, ov.title, ov.description,
                    oc.label AS category, o.stable_key
             FROM case_offenses co
             INNER JOIN offense_versions ov ON ov.id = co.offense_version_id
             INNER JOIN offenses o ON o.id = ov.offense_id
             INNER JOIN offense_categories oc ON oc.category_key = o.category_key
             WHERE co.case_id = :case_id
             ORDER BY co.is_primary DESC, co.id ASC',
            ['case_id' => $caseId]
        );

        $history = $this->fetchAll(
            'SELECT old_status, new_status, source, actor_id, reason, created_at
             FROM case_status_history WHERE case_id = :case_id ORDER BY id ASC',
            ['case_id' => $caseId]
        );

        $timeline = $this->fetchAll(
            'SELECT event_type, event_key, actor_id, payload_json, created_at
             FROM case_timeline WHERE case_id = :case_id ORDER BY id DESC LIMIT 100',
            ['case_id' => $caseId]
        );

        $package = $this->fetchOne(
            'SELECT id, version_no, status, manifest_sha256, frozen_at
             FROM evidence_packages
             WHERE case_id = :case_id
             ORDER BY version_no DESC LIMIT 1',
            ['case_id' => $caseId]
        );

        return [
            'case' => $case,
            'vehicle' => $vehicle,
            'location' => $location,
            'offenses' => $offenses,
            'history' => $history,
            'timeline' => $timeline,
            'evidence_package' => $package,
        ];
    }

    public function saveVehicle(string $userId, string $caseId, array $input): void
    {
        $case = $this->editableCase($userId, $caseId);

        $plate = trim((string) ($input['license_plate'] ?? ''));
        $type = strtoupper(trim((string) ($input['vehicle_type'] ?? '')));

        if ($plate === '') {
            throw new \InvalidArgumentException('Kennzeichen ist erforderlich.');
        }

        if (mb_strlen($plate) > 40) {
            throw new \InvalidArgumentException('Kennzeichen ist zu lang.');
        }

        if (!in_array($type, self::VEHICLE_TYPES, true)) {
            throw new \InvalidArgumentException('Ungültige Fahrzeugart.');
        }

        $normalized = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($plate, 'UTF-8')) ?? '';
        if ($normalized === '') {
            throw new \InvalidArgumentException('Kennzeichen konnte nicht normalisiert werden.');
        }

        $encrypted = $this->cipher->encrypt($plate);
        $hash = hash_hmac('sha256', $normalized, $this->searchKey);

        $stmt = $this->pdo->prepare(
            'INSERT INTO vehicles
             (id, case_id, license_plate_encrypted, license_plate_hash, vehicle_type, color, make, model, created_at, updated_at)
             VALUES (:id, :case_id, :plate, :plate_hash, :vehicle_type, :color, :make, :model, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               license_plate_encrypted = VALUES(license_plate_encrypted),
               license_plate_hash = VALUES(license_plate_hash),
               vehicle_type = VALUES(vehicle_type),
               color = VALUES(color),
               make = VALUES(make),
               model = VALUES(model),
               updated_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([
            'id' => Uuid::v4(),
            'case_id' => $caseId,
            'plate' => $encrypted,
            'plate_hash' => $hash,
            'vehicle_type' => $type,
            'color' => $this->nullable($input['color'] ?? null, 80),
            'make' => $this->nullable($input['make'] ?? null, 120),
            'model' => $this->nullable($input['model'] ?? null, 120),
        ]);

        $this->touchProgress($case, $userId);
        $this->refreshCaptureState($userId, $caseId);
        $this->timeline($caseId, 'USER', 'VEHICLE_UPDATED', $userId, ['vehicle_type' => $type]);
        $this->audit->log('CASE_VEHICLE_UPDATED', 'case', $caseId, 'USER', $userId);
    }

    public function saveLocation(string $userId, string $caseId, array $input): void
    {
        $case = $this->editableCase($userId, $caseId);

        $trafficSpace = strtoupper(trim((string) ($input['traffic_space_type'] ?? 'UNKNOWN')));
        $accessType = strtoupper(trim((string) ($input['access_type'] ?? 'UNCLEAR')));

        if (!in_array($trafficSpace, self::TRAFFIC_SPACES, true)) {
            throw new \InvalidArgumentException('Ungültiger Verkehrsraum.');
        }

        if (!in_array($accessType, self::ACCESS_TYPES, true)) {
            throw new \InvalidArgumentException('Ungültige Einordnung des Verkehrsraums.');
        }

        $latitude = $this->coordinate($input['latitude'] ?? null, -90, 90, 'Breitengrad');
        $longitude = $this->coordinate($input['longitude'] ?? null, -180, 180, 'Längengrad');
        $street = $this->nullable($input['street'] ?? null, 190);
        $city = $this->nullable($input['city'] ?? null, 120);

        if (($latitude === null) !== ($longitude === null)) {
            throw new \InvalidArgumentException('Breiten- und Längengrad müssen gemeinsam angegeben werden.');
        }

        if ($latitude === null && $longitude === null && ($street === null || $city === null)) {
            throw new \InvalidArgumentException('Bitte GPS-Koordinaten oder mindestens Straße und Ort angeben.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO locations
             (id, case_id, latitude, longitude, street, house_number, postal_code, city, district, state, country,
              direction, road_side, location_description, traffic_space_type, access_type, created_at, updated_at)
             VALUES
             (:id, :case_id, :latitude, :longitude, :street, :house_number, :postal_code, :city, :district, :state, :country,
              :direction, :road_side, :description, :traffic_space, :access_type, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               latitude = VALUES(latitude), longitude = VALUES(longitude), street = VALUES(street),
               house_number = VALUES(house_number), postal_code = VALUES(postal_code), city = VALUES(city),
               district = VALUES(district), state = VALUES(state), country = VALUES(country),
               direction = VALUES(direction), road_side = VALUES(road_side),
               location_description = VALUES(location_description),
               traffic_space_type = VALUES(traffic_space_type), access_type = VALUES(access_type),
               updated_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([
            'id' => Uuid::v4(),
            'case_id' => $caseId,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'street' => $street,
            'house_number' => $this->nullable($input['house_number'] ?? null, 30),
            'postal_code' => $this->nullable($input['postal_code'] ?? null, 20),
            'city' => $city,
            'district' => $this->nullable($input['district'] ?? null, 120),
            'state' => $this->nullable($input['state'] ?? null, 120),
            'country' => strtoupper(substr(trim((string) ($input['country'] ?? 'DE')), 0, 2)),
            'direction' => $this->nullable($input['direction'] ?? null, 120),
            'road_side' => $this->nullable($input['road_side'] ?? null, 80),
            'description' => $this->nullable($input['location_description'] ?? null, 500),
            'traffic_space' => $trafficSpace,
            'access_type' => $accessType,
        ]);

        $this->touchProgress($case, $userId);
        $this->refreshCaptureState($userId, $caseId);
        $this->timeline($caseId, 'USER', 'LOCATION_UPDATED', $userId, [
            'traffic_space_type' => $trafficSpace,
            'access_type' => $accessType,
        ]);
        $this->audit->log('CASE_LOCATION_UPDATED', 'case', $caseId, 'USER', $userId);
    }

    public function saveObservation(string $userId, string $caseId, array $input): void
    {
        $case = $this->editableCase($userId, $caseId);

        $from = $this->parseLocalDateTime($input['observed_from'] ?? null, 'Beobachtungsbeginn', false);
        $until = $this->parseLocalDateTime($input['observed_until'] ?? null, 'Beobachtungsende', true);

        if ($from === null) {
            throw new \InvalidArgumentException('Beobachtungsbeginn ist erforderlich.');
        }

        if ($until !== null && $until < $from) {
            throw new \InvalidArgumentException('Beobachtungsende darf nicht vor dem Beginn liegen.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE cases
             SET observed_from = :observed_from,
                 observed_until = :observed_until,
                 obstruction = :obstruction,
                 endangerment = :endangerment,
                 damage = :damage,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute([
            'observed_from' => $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'observed_until' => $until?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'obstruction' => $this->booleanInput($input['obstruction'] ?? null) ? 1 : 0,
            'endangerment' => $this->booleanInput($input['endangerment'] ?? null) ? 1 : 0,
            'damage' => $this->booleanInput($input['damage'] ?? null) ? 1 : 0,
            'id' => $caseId,
        ]);

        $this->touchProgress($case, $userId);
        $this->refreshCaptureState($userId, $caseId);
        $this->timeline($caseId, 'USER', 'OBSERVATION_UPDATED', $userId, [
            'has_end' => $until !== null,
            'obstruction' => $this->booleanInput($input['obstruction'] ?? null),
            'endangerment' => $this->booleanInput($input['endangerment'] ?? null),
            'damage' => $this->booleanInput($input['damage'] ?? null),
        ]);
        $this->audit->log('CASE_OBSERVATION_UPDATED', 'case', $caseId, 'USER', $userId);
    }

    public function reviewSummary(string $userId, string $caseId): array
    {
        $data = $this->findOwned($userId, $caseId);

        if ($data === null) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        $case = $data['case'];
        $vehicle = $data['vehicle'];
        $location = $data['location'];
        $offense = $data['offenses'][0] ?? null;

        $missing = [];
        $warnings = [];

        if ($vehicle === null || empty($vehicle['license_plate']) || empty($vehicle['vehicle_type'])) {
            $missing[] = 'Fahrzeug und Kennzeichen vollständig erfassen.';
        }

        $hasCoordinates = $location !== null
            && $location['latitude'] !== null
            && $location['longitude'] !== null;
        $hasAddress = $location !== null
            && trim((string) ($location['street'] ?? '')) !== ''
            && trim((string) ($location['city'] ?? '')) !== '';

        if (!$hasCoordinates && !$hasAddress) {
            $missing[] = 'Standort per GPS oder mit Straße und Ort erfassen.';
        }

        if (($case['observed_from'] ?? null) === null) {
            $missing[] = 'Beobachtungsbeginn erfassen.';
        }

        if (
            $offense === null
            || ($offense['stable_key'] ?? '') === 'UNCLASSIFIED_PARKING'
            || (int) ($offense['user_confirmed'] ?? 0) !== 1
        ) {
            $missing[] = 'Konkreten Tatbestand auswählen und bestätigen.';
        }

        if ($location !== null && ($location['traffic_space_type'] ?? 'UNKNOWN') === 'UNKNOWN') {
            $warnings[] = 'Der konkrete Verkehrsraum ist noch als unklar markiert.';
        }

        if ($location !== null && ($location['access_type'] ?? 'UNCLEAR') === 'UNCLEAR') {
            $warnings[] = 'Es ist noch unklar, ob der Ort öffentlich oder privat ist.';
        }

        if (($case['observed_until'] ?? null) === null) {
            $warnings[] = 'Es wurde kein Beobachtungsende erfasst; eine Mindestdauer kann so nicht abgeleitet werden.';
        }

        return [
            'data' => $data,
            'missing' => $missing,
            'warnings' => $warnings,
            'ready' => $missing === [],
        ];
    }

    public function confirmCoreReview(
        string $userId,
        string $caseId,
        bool $warningsAcknowledged = false
    ): void {
        $summary = $this->reviewSummary($userId, $caseId);

        if (!$summary['ready']) {
            throw new \DomainException('Grunddaten sind noch nicht vollständig.');
        }

        if ($summary['warnings'] !== [] && !$warningsAcknowledged) {
            throw new \DomainException('Die Hinweise müssen vor dem Übergang zur Beweiserfassung bestätigt werden.');
        }

        $status = (string) $summary['data']['case']['status'];

        if (($summary['data']['evidence_package'] ?? null) !== null) {
            throw new \DomainException('Der Grunddaten-Review ist bereits abgeschlossen; der Vorgang befindet sich im Evidence-Review.');
        }

        if ($status !== CaseStatus::READY_FOR_REVIEW) {
            throw new \DomainException('Vorgang ist aktuell nicht für den Grunddaten-Review bereit.');
        }

        $this->changeStatus(
            $userId,
            $caseId,
            CaseStatus::WAITING_FOR_EVIDENCE,
            'Grunddaten geprüft; Übergang zur Beweiserfassung'
        );

        $this->timeline($caseId, 'USER', 'CORE_REVIEW_CONFIRMED', $userId, [
            'warnings_acknowledged' => $warningsAcknowledged,
        ]);
        $this->audit->log('CASE_CORE_REVIEW_CONFIRMED', 'case', $caseId, 'USER', $userId, [
            'warnings_acknowledged' => $warningsAcknowledged,
        ]);
    }

    public function setPrimaryOffense(string $userId, string $caseId, string $offenseVersionId): void
    {
        $case = $this->editableCase($userId, $caseId);

        $version = $this->fetchOne(
            'SELECT ov.id
             FROM offense_versions ov
             INNER JOIN offenses o ON o.id = ov.offense_id
             INNER JOIN (
                SELECT offense_id, MAX(version) AS max_version
                FROM offense_versions
                GROUP BY offense_id
             ) latest ON latest.offense_id = ov.offense_id AND latest.max_version = ov.version
             WHERE ov.id = :id AND o.active = 1
             LIMIT 1',
            ['id' => $offenseVersionId]
        );

        if ($version === null) {
            throw new \InvalidArgumentException('Tatbestand ist nicht verfügbar.');
        }

        try {
            $this->pdo->beginTransaction();

            $this->pdo->prepare(
                'UPDATE case_offenses SET is_primary = 0 WHERE case_id = :case_id'
            )->execute(['case_id' => $caseId]);

            $stmt = $this->pdo->prepare(
                'INSERT INTO case_offenses
                 (case_id, offense_version_id, is_primary, user_confirmed, ai_suggested, confidence, created_at)
                 VALUES (:case_id, :version_id, 1, 1, 0, NULL, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE is_primary = 1, user_confirmed = 1'
            );
            $stmt->execute([
                'case_id' => $caseId,
                'version_id' => $offenseVersionId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->touchProgress($case, $userId);
        $this->refreshCaptureState($userId, $caseId);
        $this->timeline($caseId, 'USER', 'PRIMARY_OFFENSE_SET', $userId, ['offense_version_id' => $offenseVersionId]);
        $this->audit->log('CASE_PRIMARY_OFFENSE_SET', 'case', $caseId, 'USER', $userId);
    }

    public function availableOffenses(): array
    {
        return $this->fetchAll(
            'SELECT ov.id, ov.version, ov.code, ov.title, ov.description, oc.label AS category, o.stable_key
             FROM offense_versions ov
             INNER JOIN offenses o ON o.id = ov.offense_id
             INNER JOIN offense_categories oc ON oc.category_key = o.category_key
             INNER JOIN (
                SELECT offense_id, MAX(version) AS max_version
                FROM offense_versions
                GROUP BY offense_id
             ) latest ON latest.offense_id = ov.offense_id AND latest.max_version = ov.version
             WHERE o.active = 1 AND oc.active = 1
             ORDER BY oc.sort_order, ov.title',
            []
        );
    }

    public function dashboardSummary(string $userId): array
    {
        $countsStmt = $this->pdo->prepare(
            'SELECT
                SUM(CASE WHEN status NOT IN ("CLOSED","ARCHIVED","DELETION_PENDING") THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN status IN ("DRAFT","CAPTURE_IN_PROGRESS","WAITING_FOR_EVIDENCE") THEN 1 ELSE 0 END) AS draft_count,
                SUM(CASE WHEN status IN ("USER_ACTION_REQUIRED","REVIEW_REQUIRED","DELIVERY_FAILED") THEN 1 ELSE 0 END) AS action_count,
                SUM(CASE WHEN status = "DELIVERY_FAILED" THEN 1 ELSE 0 END) AS delivery_error_count
             FROM cases
             WHERE user_id = :user_id'
        );
        $countsStmt->execute(['user_id' => $userId]);
        $counts = $countsStmt->fetch() ?: [];

        $recentStmt = $this->pdo->prepare(
            'SELECT id, public_number, status, updated_at
             FROM cases
             WHERE user_id = :user_id
             ORDER BY updated_at DESC
             LIMIT 5'
        );
        $recentStmt->execute(['user_id' => $userId]);

        return [
            'open' => (int) ($counts['open_count'] ?? 0),
            'drafts' => (int) ($counts['draft_count'] ?? 0),
            'actions' => (int) ($counts['action_count'] ?? 0),
            'delivery_errors' => (int) ($counts['delivery_error_count'] ?? 0),
            'recent' => $recentStmt->fetchAll(),
        ];
    }

    public function changeStatus(string $userId, string $caseId, string $to, ?string $reason = null): void
    {
        $case = $this->findRaw($caseId);
        if ($case === null) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        $this->authorization->authorize($userId, 'case.edit_own', (string) $case['user_id']);
        (new CaseStatusMachine())->assert((string) $case['status'], $to);

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(
                'UPDATE cases SET status = :status, updated_at = UTC_TIMESTAMP() WHERE id = :id'
            );
            $stmt->execute(['status' => $to, 'id' => $caseId]);

            $this->recordStatus($caseId, (string) $case['status'], $to, 'USER', $userId, $reason);
            $this->timeline($caseId, 'USER', 'STATUS_CHANGED', $userId, [
                'from' => $case['status'],
                'to' => $to,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log('CASE_STATUS_CHANGED', 'case', $caseId, 'USER', $userId, [
            'from' => $case['status'],
            'to' => $to,
        ]);
    }

    private function editableCase(string $userId, string $caseId): array
    {
        $case = $this->findRaw($caseId);

        if ($case === null) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        $this->authorization->authorize($userId, 'case.edit_own', (string) $case['user_id']);

        if (!in_array($case['status'], [
            CaseStatus::DRAFT,
            CaseStatus::CAPTURE_IN_PROGRESS,
            CaseStatus::WAITING_FOR_EVIDENCE,
            CaseStatus::READY_FOR_REVIEW,
            CaseStatus::REVIEW_REQUIRED,
        ], true)) {
            throw new \DomainException('Vorgang kann in diesem Status nicht direkt bearbeitet werden.');
        }

        return $case;
    }

    private function touchProgress(array $case, string $userId): void
    {
        $this->pdo->prepare('UPDATE cases SET updated_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute(['id' => $case['id']]);

        if ($case['status'] === CaseStatus::DRAFT) {
            $this->changeStatus($userId, (string) $case['id'], CaseStatus::CAPTURE_IN_PROGRESS, 'Erfassung begonnen');
            return;
        }

        if (in_array($case['status'], [
            CaseStatus::READY_FOR_REVIEW,
            CaseStatus::REVIEW_REQUIRED,
            CaseStatus::WAITING_FOR_EVIDENCE,
        ], true)) {
            $this->changeStatus(
                $userId,
                (string) $case['id'],
                CaseStatus::CAPTURE_IN_PROGRESS,
                'Grunddaten nachträglich geändert'
            );
        }
    }

    private function refreshCaptureState(string $userId, string $caseId): void
    {
        $case = $this->findRaw($caseId);

        if ($case === null || $case['status'] !== CaseStatus::CAPTURE_IN_PROGRESS) {
            return;
        }

        $vehicleExists = $this->fetchOne(
            'SELECT 1 AS found FROM vehicles WHERE case_id = :case_id LIMIT 1',
            ['case_id' => $caseId]
        ) !== null;

        $locationExists = $this->fetchOne(
            'SELECT 1 AS found FROM locations WHERE case_id = :case_id LIMIT 1',
            ['case_id' => $caseId]
        ) !== null;

        $observationComplete = ($case['observed_from'] ?? null) !== null;

        $offense = $this->fetchOne(
            'SELECT o.stable_key
             FROM case_offenses co
             INNER JOIN offense_versions ov ON ov.id = co.offense_version_id
             INNER JOIN offenses o ON o.id = ov.offense_id
             WHERE co.case_id = :case_id AND co.is_primary = 1 AND co.user_confirmed = 1
             LIMIT 1',
            ['case_id' => $caseId]
        );

        if (
            $vehicleExists
            && $locationExists
            && $observationComplete
            && $offense !== null
            && ($offense['stable_key'] ?? '') !== 'UNCLASSIFIED_PARKING'
        ) {
            $this->changeStatus(
                $userId,
                $caseId,
                CaseStatus::READY_FOR_REVIEW,
                'Grunddaten vollständig; Review erforderlich'
            );
        }
    }

    private function findRaw(string $caseId): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM cases WHERE id = :id LIMIT 1',
            ['id' => $caseId]
        );
    }

    private function recordStatus(
        string $caseId,
        ?string $old,
        string $new,
        string $source,
        ?string $actorId,
        ?string $reason
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO case_status_history
             (case_id, old_status, new_status, source, actor_id, reason, created_at)
             VALUES (:case_id, :old_status, :new_status, :source, :actor_id, :reason, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'case_id' => $caseId,
            'old_status' => $old,
            'new_status' => $new,
            'source' => $source,
            'actor_id' => $actorId,
            'reason' => $reason,
        ]);
    }

    private function timeline(string $caseId, string $type, string $key, ?string $actorId, array $payload = []): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO case_timeline
             (case_id, event_type, event_key, actor_id, payload_json, created_at)
             VALUES (:case_id, :event_type, :event_key, :actor_id, :payload, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'case_id' => $caseId,
            'event_type' => $type,
            'event_key' => $key,
            'actor_id' => $actorId,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
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

    private function parseLocalDateTime(mixed $value, string $label, bool $nullable): ?\DateTimeImmutable
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            if ($nullable) {
                return null;
            }

            throw new \InvalidArgumentException($label . ' ist erforderlich.');
        }

        $timezone = new \DateTimeZone($this->timezone);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, $timezone);

        if (!$date || $date->format('Y-m-d\\TH:i') !== $value) {
            throw new \InvalidArgumentException($label . ' ist ungültig.');
        }

        return $date;
    }

    private function toLocalInput(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));

        return $date->setTimezone(new \DateTimeZone($this->timezone))->format('Y-m-d\\TH:i');
    }

    private function durationSeconds(mixed $from, mixed $until): ?int
    {
        if (!is_string($from) || $from === '' || !is_string($until) || $until === '') {
            return null;
        }

        $start = new \DateTimeImmutable($from, new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable($until, new \DateTimeZone('UTC'));

        return max(0, $end->getTimestamp() - $start->getTimestamp());
    }

    private function booleanInput(mixed $value): bool
    {
        return in_array($value, [1, '1', true, 'true', 'on', 'yes'], true);
    }

    private function decryptNullable(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $this->cipher->decrypt($value) : null;
    }

    private function nullable(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function coordinate(mixed $value, float $min, float $max, string $label): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException($label . ' ist ungültig.');
        }

        $number = (float) $value;

        if ($number < $min || $number > $max) {
            throw new \InvalidArgumentException($label . ' liegt außerhalb des gültigen Bereichs.');
        }

        return $number;
    }

}
