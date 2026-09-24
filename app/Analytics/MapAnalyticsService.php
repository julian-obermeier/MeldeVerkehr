<?php

declare(strict_types=1);

namespace MeldeVerkehr\Analytics;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Support\Uuid;
use PDO;

final class MapAnalyticsService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization,
        private readonly CaseService $cases,
        private readonly AuditLogger $audit
    ) {
    }

    public function mapData(string $userId, ?string $status = null): array
    {
        $sql =
            'SELECT c.id, c.public_number, c.status, c.observed_from, c.created_at,
                    l.latitude, l.longitude, l.street, l.house_number, l.postal_code, l.city,
                    o.category_key AS offense_category, ov.title AS offense_title
             FROM cases c
             INNER JOIN locations l ON l.case_id = c.id
             LEFT JOIN case_offenses co ON co.case_id = c.id AND co.is_primary = 1
             LEFT JOIN offense_versions ov ON ov.id = co.offense_version_id
             LEFT JOIN offenses o ON o.id = ov.offense_id
             WHERE c.user_id = :user_id
               AND l.latitude IS NOT NULL
               AND l.longitude IS NOT NULL';

        $params = ['user_id' => $userId];

        if ($status !== null && trim($status) !== '') {
            $sql .= ' AND c.status = :status';
            $params['status'] = trim($status);
        }

        $sql .= ' ORDER BY COALESCE(c.observed_from, c.created_at) DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn(array $row): array => [
            'case_id' => (string) $row['id'],
            'public_number' => (string) $row['public_number'],
            'status' => (string) $row['status'],
            'observed_at' => $row['observed_from'],
            'latitude' => (float) $row['latitude'],
            'longitude' => (float) $row['longitude'],
            'street' => $row['street'],
            'house_number' => $row['house_number'],
            'postal_code' => $row['postal_code'],
            'city' => $row['city'],
            'offense_category' => $row['offense_category'],
            'offense_title' => $row['offense_title'],
        ], $stmt->fetchAll());
    }

    public function hotspotCandidates(string $userId, int $minimumCases = 2): array
    {
        $minimumCases = max(2, min(50, $minimumCases));
        $points = $this->mapData($userId);
        $groups = [];

        foreach ($points as $point) {
            $key = number_format(round($point['latitude'], 3), 3, '.', '')
                . ':'
                . number_format(round($point['longitude'], 3), 3, '.', '');

            $groups[$key][] = $point;
        }

        $result = [];
        foreach ($groups as $key => $rows) {
            if (count($rows) < $minimumCases) {
                continue;
            }

            $lat = array_sum(array_column($rows, 'latitude')) / count($rows);
            $lon = array_sum(array_column($rows, 'longitude')) / count($rows);
            $result[] = [
                'cluster_key' => $key,
                'case_count' => count($rows),
                'latitude' => round($lat, 7),
                'longitude' => round($lon, 7),
                'city' => $this->mostCommon(array_column($rows, 'city')),
                'street' => $this->mostCommon(array_column($rows, 'street')),
                'case_ids' => array_column($rows, 'case_id'),
            ];
        }

        usort($result, static fn(array $a, array $b): int => $b['case_count'] <=> $a['case_count']);

        return $result;
    }

    public function createProblemArea(
        string $userId,
        string $name,
        float $latitude,
        float $longitude,
        int $radiusM,
        string $source = 'MANUAL',
        ?string $city = null,
        ?string $street = null
    ): array {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 190) {
            throw new \InvalidArgumentException('Name der Problemstelle ist ungültig.');
        }

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException('Koordinaten sind ungültig.');
        }

        $radiusM = max(25, min(5000, $radiusM));
        $source = strtoupper(trim($source));
        if (!in_array($source, ['MANUAL','AUTO'], true)) {
            throw new \InvalidArgumentException('Quelle der Problemstelle ist ungültig.');
        }

        $id = Uuid::v4();
        $stmt = $this->pdo->prepare(
            'INSERT INTO problem_areas
             (id, user_id, name, center_latitude, center_longitude, radius_m, status, source,
              city, street, created_at, updated_at)
             VALUES
             (:id, :user_id, :name, :lat, :lon, :radius_m, "ACTIVE", :source,
              :city, :street, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'name' => $name,
            'lat' => $latitude,
            'lon' => $longitude,
            'radius_m' => $radiusM,
            'source' => $source,
            'city' => $this->nullable($city),
            'street' => $this->nullable($street),
        ]);

        $this->syncProblemArea($userId, $id);

        $this->audit->log('PROBLEM_AREA_CREATED', 'problem_area', $id, 'USER', $userId, [
            'radius_m' => $radiusM,
            'source' => $source,
        ]);

        return $this->problemArea($userId, $id);
    }

    public function syncProblemArea(string $userId, string $areaId): array
    {
        $area = $this->ownedArea($userId, $areaId);
        $points = $this->mapData($userId);

        $linked = [];
        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare(
                'DELETE FROM problem_area_cases WHERE problem_area_id = :area_id'
            )->execute(['area_id' => $areaId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO problem_area_cases
                 (problem_area_id, case_id, distance_m, assignment_source, created_at)
                 VALUES (:area_id, :case_id, :distance_m, "AUTO", UTC_TIMESTAMP())'
            );

            foreach ($points as $point) {
                $distance = $this->distanceMeters(
                    (float) $area['center_latitude'],
                    (float) $area['center_longitude'],
                    (float) $point['latitude'],
                    (float) $point['longitude']
                );

                if ($distance > (int) $area['radius_m']) {
                    continue;
                }

                $insert->execute([
                    'area_id' => $areaId,
                    'case_id' => $point['case_id'],
                    'distance_m' => round($distance, 2),
                ]);
                $linked[] = $point['case_id'];
            }

            $this->pdo->prepare(
                'UPDATE problem_areas SET updated_at = UTC_TIMESTAMP() WHERE id = :id'
            )->execute(['id' => $areaId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['area_id' => $areaId, 'linked_count' => count($linked), 'case_ids' => $linked];
    }

    public function problemAreas(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pa.*,
                    COUNT(pac.case_id) AS case_count,
                    MIN(c.observed_from) AS first_observed_at,
                    MAX(c.observed_from) AS last_observed_at
             FROM problem_areas pa
             LEFT JOIN problem_area_cases pac ON pac.problem_area_id = pa.id
             LEFT JOIN cases c ON c.id = pac.case_id AND c.user_id = pa.user_id
             WHERE pa.user_id = :user_id
             GROUP BY pa.id
             ORDER BY case_count DESC, pa.updated_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function problemArea(string $userId, string $areaId): array
    {
        $area = $this->ownedArea($userId, $areaId);

        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.public_number, c.status, c.observed_from,
                    l.street, l.house_number, l.postal_code, l.city,
                    pac.distance_m,
                    o.category_key AS offense_category, ov.title AS offense_title
             FROM problem_area_cases pac
             INNER JOIN cases c ON c.id = pac.case_id AND c.user_id = :user_id
             LEFT JOIN locations l ON l.case_id = c.id
             LEFT JOIN case_offenses co ON co.case_id = c.id AND co.is_primary = 1
             LEFT JOIN offense_versions ov ON ov.id = co.offense_version_id
             LEFT JOIN offenses o ON o.id = ov.offense_id
             WHERE pac.problem_area_id = :area_id
             ORDER BY COALESCE(c.observed_from, c.created_at) DESC'
        );
        $stmt->execute(['user_id' => $userId, 'area_id' => $areaId]);

        $area['cases'] = $stmt->fetchAll();
        $area['metrics'] = $this->areaMetrics($userId, $areaId, null, null);

        return $area;
    }

    public function analytics(
        string $userId,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null
    ): array {
        [$where, $params] = $this->dateWhere($userId, $from, $to, 'c');

        $totalStmt = $this->pdo->prepare('SELECT COUNT(*) FROM cases c WHERE ' . $where);
        $totalStmt->execute($params);
        $total = (int) $totalStmt->fetchColumn();

        $statusStmt = $this->pdo->prepare(
            'SELECT c.status AS label, COUNT(*) AS count
             FROM cases c WHERE ' . $where . '
             GROUP BY c.status ORDER BY count DESC'
        );
        $statusStmt->execute($params);

        $cityStmt = $this->pdo->prepare(
            'SELECT COALESCE(l.city, "Unbekannt") AS label, COUNT(*) AS count
             FROM cases c
             LEFT JOIN locations l ON l.case_id = c.id
             WHERE ' . $where . '
             GROUP BY COALESCE(l.city, "Unbekannt")
             ORDER BY count DESC LIMIT 20'
        );
        $cityStmt->execute($params);

        $offenseStmt = $this->pdo->prepare(
            'SELECT COALESCE(o.category_key, "UNCLASSIFIED") AS label, COUNT(*) AS count
             FROM cases c
             LEFT JOIN case_offenses co ON co.case_id = c.id AND co.is_primary = 1
             LEFT JOIN offense_versions ov ON ov.id = co.offense_version_id
             LEFT JOIN offenses o ON o.id = ov.offense_id
             WHERE ' . $where . '
             GROUP BY COALESCE(o.category_key, "UNCLASSIFIED")
             ORDER BY count DESC'
        );
        $offenseStmt->execute($params);

        $monthStmt = $this->pdo->prepare(
            'SELECT DATE_FORMAT(COALESCE(c.observed_from, c.created_at), "%Y-%m") AS label,
                    COUNT(*) AS count
             FROM cases c WHERE ' . $where . '
             GROUP BY DATE_FORMAT(COALESCE(c.observed_from, c.created_at), "%Y-%m")
             ORDER BY label'
        );
        $monthStmt->execute($params);

        return [
            'total' => $total,
            'status' => $statusStmt->fetchAll(),
            'cities' => $cityStmt->fetchAll(),
            'offense_categories' => $offenseStmt->fetchAll(),
            'months' => $monthStmt->fetchAll(),
            'period' => [
                'from' => $from?->format('Y-m-d'),
                'to' => $to?->format('Y-m-d'),
            ],
        ];
    }

    public function createSnapshot(
        string $userId,
        string $areaId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        $this->ownedArea($userId, $areaId);

        if ($to < $from) {
            throw new \InvalidArgumentException('Zeitraum ist ungültig.');
        }

        $metrics = $this->areaMetrics($userId, $areaId, $from, $to);
        $json = json_encode(
            $metrics,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $hash = hash('sha256', $json);
        $version = $this->nextVersion('problem_area_snapshots', 'problem_area_id', $areaId);
        $id = Uuid::v4();

        $stmt = $this->pdo->prepare(
            'INSERT INTO problem_area_snapshots
             (id, problem_area_id, version_no, period_start, period_end, metrics_json,
              metrics_sha256, created_by_user_id, created_at)
             VALUES
             (:id, :area_id, :version_no, :period_start, :period_end, :metrics,
              :sha256, :user_id, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'id' => $id,
            'area_id' => $areaId,
            'version_no' => $version,
            'period_start' => $from->format('Y-m-d'),
            'period_end' => $to->format('Y-m-d'),
            'metrics' => $json,
            'sha256' => $hash,
            'user_id' => $userId,
        ]);

        return [
            'id' => $id,
            'version_no' => $version,
            'metrics' => $metrics,
            'metrics_sha256' => $hash,
        ];
    }

    public function createMunicipalReport(
        string $userId,
        string $areaId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        $area = $this->ownedArea($userId, $areaId);
        $snapshot = $this->createSnapshot($userId, $areaId, $from, $to);
        $version = $this->nextVersion('municipal_reports', 'problem_area_id', $areaId);
        $id = Uuid::v4();

        $report = [
            'schema_version' => 1,
            'title' => 'Anonymisierter Problemstellenbericht – ' . (string) $area['name'],
            'problem_area' => [
                'name' => (string) $area['name'],
                'city' => $area['city'],
                'street' => $area['street'],
                'center_latitude' => round((float) $area['center_latitude'], 5),
                'center_longitude' => round((float) $area['center_longitude'], 5),
                'radius_m' => (int) $area['radius_m'],
            ],
            'period' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'metrics' => $snapshot['metrics'],
            'privacy' => [
                'contains_license_plates' => false,
                'contains_vehicle_owner_data' => false,
                'contains_case_ids' => false,
                'contains_photos' => false,
                'scope' => 'aggregated_own_cases_only',
            ],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $json = json_encode(
            $report,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $hash = hash('sha256', $json);

        $stmt = $this->pdo->prepare(
            'INSERT INTO municipal_reports
             (id, user_id, problem_area_id, snapshot_id, version_no, title, report_json,
              report_sha256, status, created_at, updated_at)
             VALUES
             (:id, :user_id, :area_id, :snapshot_id, :version_no, :title, :report_json,
              :sha256, "DRAFT", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'area_id' => $areaId,
            'snapshot_id' => $snapshot['id'],
            'version_no' => $version,
            'title' => $report['title'],
            'report_json' => $json,
            'sha256' => $hash,
        ]);

        $this->audit->log('MUNICIPAL_REPORT_CREATED', 'municipal_report', $id, 'USER', $userId, [
            'problem_area_id' => $areaId,
            'version_no' => $version,
            'report_sha256' => $hash,
            'case_count' => $snapshot['metrics']['case_count'],
        ]);

        return [
            'id' => $id,
            'version_no' => $version,
            'report' => $report,
            'report_sha256' => $hash,
        ];
    }

    public function municipalReports(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT mr.id, mr.version_no, mr.title, mr.status, mr.report_sha256, mr.created_at,
                    pa.name AS problem_area_name
             FROM municipal_reports mr
             INNER JOIN problem_areas pa ON pa.id = mr.problem_area_id AND pa.user_id = mr.user_id
             WHERE mr.user_id = :user_id
             ORDER BY mr.created_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function municipalReport(string $userId, string $reportId): array
    {
        $row = $this->fetchOne(
            'SELECT mr.*
             FROM municipal_reports mr
             WHERE mr.id = :id AND mr.user_id = :user_id LIMIT 1',
            ['id' => $reportId, 'user_id' => $userId]
        );

        if ($row === null) {
            throw new \DomainException('Problembericht nicht gefunden.');
        }

        if (!hash_equals((string) $row['report_sha256'], hash('sha256', (string) $row['report_json']))) {
            throw new \RuntimeException('Integrität des Problemberichts ist verletzt.');
        }

        $decoded = json_decode((string) $row['report_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Problembericht ist ungültig.');
        }

        $row['report'] = $decoded;
        unset($row['report_json']);

        return $row;
    }

    private function areaMetrics(
        string $userId,
        string $areaId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to
    ): array {
        $where = 'pac.problem_area_id = :area_id AND c.user_id = :user_id';
        $params = ['area_id' => $areaId, 'user_id' => $userId];

        if ($from !== null) {
            $where .= ' AND COALESCE(c.observed_from, c.created_at) >= :from';
            $params['from'] = $from->format('Y-m-d 00:00:00');
        }
        if ($to !== null) {
            $where .= ' AND COALESCE(c.observed_from, c.created_at) <= :to';
            $params['to'] = $to->format('Y-m-d 23:59:59');
        }

        $base =
            ' FROM problem_area_cases pac
              INNER JOIN cases c ON c.id = pac.case_id
              LEFT JOIN case_offenses co ON co.case_id = c.id AND co.is_primary = 1
              LEFT JOIN offense_versions ov ON ov.id = co.offense_version_id
              LEFT JOIN offenses o ON o.id = ov.offense_id
              WHERE ' . $where;

        $countStmt = $this->pdo->prepare('SELECT COUNT(*)' . $base);
        $countStmt->execute($params);

        $statusStmt = $this->pdo->prepare(
            'SELECT c.status AS label, COUNT(*) AS count' . $base . ' GROUP BY c.status ORDER BY count DESC'
        );
        $statusStmt->execute($params);

        $offenseStmt = $this->pdo->prepare(
            'SELECT COALESCE(o.category_key, "UNCLASSIFIED") AS label, COUNT(*) AS count'
            . $base
            . ' GROUP BY COALESCE(o.category_key, "UNCLASSIFIED") ORDER BY count DESC'
        );
        $offenseStmt->execute($params);

        $hourStmt = $this->pdo->prepare(
            'SELECT HOUR(COALESCE(c.observed_from, c.created_at)) AS hour, COUNT(*) AS count'
            . $base
            . ' GROUP BY HOUR(COALESCE(c.observed_from, c.created_at)) ORDER BY hour'
        );
        $hourStmt->execute($params);

        return [
            'case_count' => (int) $countStmt->fetchColumn(),
            'status_counts' => $statusStmt->fetchAll(),
            'offense_category_counts' => $offenseStmt->fetchAll(),
            'hour_distribution' => $hourStmt->fetchAll(),
        ];
    }

    private function ownedArea(string $userId, string $areaId): array
    {
        $row = $this->fetchOne(
            'SELECT * FROM problem_areas WHERE id = :id AND user_id = :user_id LIMIT 1',
            ['id' => $areaId, 'user_id' => $userId]
        );

        if ($row === null) {
            throw new \DomainException('Problemstelle nicht gefunden.');
        }

        return $row;
    }

    private function dateWhere(
        string $userId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        string $alias
    ): array {
        $where = $alias . '.user_id = :user_id';
        $params = ['user_id' => $userId];

        if ($from !== null) {
            $where .= ' AND COALESCE(' . $alias . '.observed_from, ' . $alias . '.created_at) >= :from';
            $params['from'] = $from->format('Y-m-d 00:00:00');
        }
        if ($to !== null) {
            $where .= ' AND COALESCE(' . $alias . '.observed_from, ' . $alias . '.created_at) <= :to';
            $params['to'] = $to->format('Y-m-d 23:59:59');
        }

        return [$where, $params];
    }

    private function nextVersion(string $table, string $foreignKey, string $id): int
    {
        $allowed = [
            'problem_area_snapshots' => 'problem_area_id',
            'municipal_reports' => 'problem_area_id',
        ];

        if (($allowed[$table] ?? null) !== $foreignKey) {
            throw new \InvalidArgumentException('Ungültiger Versionszähler.');
        }

        $stmt = $this->pdo->prepare(
            sprintf(
                'SELECT COALESCE(MAX(version_no), 0) + 1 FROM %s WHERE %s = :id',
                $table,
                $foreignKey
            )
        );
        $stmt->execute(['id' => $id]);

        return max(1, (int) $stmt->fetchColumn());
    }

    private function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371000.0;
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $deltaPhi = deg2rad($lat2 - $lat1);
        $deltaLambda = deg2rad($lon2 - $lon1);

        $a = sin($deltaPhi / 2) ** 2
            + cos($phi1) * cos($phi2) * sin($deltaLambda / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function mostCommon(array $values): ?string
    {
        $values = array_values(array_filter(array_map(
            static fn(mixed $value): string => trim((string) $value),
            $values
        ), static fn(string $value): bool => $value !== ''));

        if ($values === []) {
            return null;
        }

        $counts = array_count_values($values);
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
