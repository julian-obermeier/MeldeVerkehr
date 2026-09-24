<?php

declare(strict_types=1);

namespace MeldeVerkehr\Operations;

use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Uuid;
use PDO;

final class CaseSearchService
{
    private const FILTER_KEYS = ['q','status','city','authority_id','date_from','date_to'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly SecretCipher $cipher,
        private readonly string $searchKey
    ) {
    }

    public function search(string $userId, array $filters = [], int $limit = 100): array
    {
        $filters = $this->normalizeFilters($filters);
        $limit = max(1, min(250, $limit));

        $sql =
            'SELECT c.id, c.public_number, c.status, c.observed_from, c.updated_at,
                    l.street, l.house_number, l.postal_code, l.city,
                    v.vehicle_type, v.license_plate_encrypted,
                    ov.title AS offense_title, ov.code AS offense_code,
                    oc.label AS offense_category,
                    (
                        SELECT a.name
                        FROM dispatches d
                        INNER JOIN authorities a ON a.id = d.authority_id
                        WHERE d.case_id = c.id
                        ORDER BY d.created_at DESC
                        LIMIT 1
                    ) AS authority_name,
                    (
                        SELECT d.authority_id
                        FROM dispatches d
                        WHERE d.case_id = c.id
                        ORDER BY d.created_at DESC
                        LIMIT 1
                    ) AS authority_id
             FROM cases c
             LEFT JOIN locations l ON l.case_id = c.id
             LEFT JOIN vehicles v ON v.case_id = c.id
             LEFT JOIN case_offenses co ON co.case_id = c.id AND co.is_primary = 1
             LEFT JOIN offense_versions ov ON ov.id = co.offense_version_id
             LEFT JOIN offenses o ON o.id = ov.offense_id
             LEFT JOIN offense_categories oc ON oc.category_key = o.category_key
             WHERE c.user_id = :user_id';

        $params = ['user_id' => $userId];

        if ($filters['status'] !== null) {
            $sql .= ' AND c.status = :status';
            $params['status'] = $filters['status'];
        }

        if ($filters['city'] !== null) {
            $sql .= ' AND l.city = :city';
            $params['city'] = $filters['city'];
        }

        if ($filters['authority_id'] !== null) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM dispatches sd
                WHERE sd.case_id = c.id AND sd.authority_id = :authority_id
            )';
            $params['authority_id'] = $filters['authority_id'];
        }

        if ($filters['date_from'] !== null) {
            $sql .= ' AND COALESCE(c.observed_from, c.created_at) >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if ($filters['date_to'] !== null) {
            $sql .= ' AND COALESCE(c.observed_from, c.created_at) <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        if ($filters['q'] !== null) {
            $normalizedPlate = preg_replace(
                '/[^A-Z0-9]/',
                '',
                mb_strtoupper($filters['q'], 'UTF-8')
            ) ?? '';

            $sql .= ' AND (
                c.public_number LIKE :q_number
                OR l.street LIKE :q_street
                OR l.city LIKE :q_city
                OR ov.title LIKE :q_offense
                OR ov.code LIKE :q_code
                OR oc.label LIKE :q_category
                OR EXISTS (
                    SELECT 1
                    FROM dispatches qd
                    INNER JOIN authorities qa ON qa.id = qd.authority_id
                    WHERE qd.case_id = c.id AND qa.name LIKE :q_authority
                )
                OR v.license_plate_hash = :plate_hash
            )';

            $like = '%' . $filters['q'] . '%';
            $params += [
                'q_number' => $like,
                'q_street' => $like,
                'q_city' => $like,
                'q_offense' => $like,
                'q_code' => $like,
                'q_category' => $like,
                'q_authority' => $like,
                'plate_hash' => hash_hmac('sha256', $normalizedPlate, $this->searchKey),
            ];
        }

        $sql .= ' ORDER BY c.updated_at DESC LIMIT ' . $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['license_plate'] = $this->decryptNullable($row['license_plate_encrypted'] ?? null);
            unset($row['license_plate_encrypted']);
        }
        unset($row);

        return [
            'filters' => $filters,
            'results' => $rows,
            'count' => count($rows),
        ];
    }

    public function saveFilter(string $userId, string $name, array $filters): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Filtername ist ungültig.');
        }

        $filters = $this->normalizeFilters($filters);
        $json = json_encode(
            $filters,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $stmt = $this->pdo->prepare(
            'SELECT id FROM saved_case_filters
             WHERE user_id = :user_id AND name = :name LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'name' => $name]);
        $existing = $stmt->fetchColumn();
        $id = is_string($existing) && $existing !== '' ? $existing : Uuid::v4();

        $this->pdo->prepare(
            'INSERT INTO saved_case_filters
             (id, user_id, name, filter_json, created_at, updated_at)
             VALUES
             (:id, :user_id, :name, :filter_json, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                filter_json = VALUES(filter_json),
                updated_at = UTC_TIMESTAMP()'
        )->execute([
            'id' => $id,
            'user_id' => $userId,
            'name' => $name,
            'filter_json' => $json,
        ]);

        return [
            'id' => $id,
            'name' => $name,
            'filters' => $filters,
            'live_count' => $this->search($userId, $filters, 250)['count'],
        ];
    }

    public function savedFilters(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, filter_json, updated_at
             FROM saved_case_filters
             WHERE user_id = :user_id
             ORDER BY updated_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $filters = json_decode((string) $row['filter_json'], true, 512, JSON_THROW_ON_ERROR);
            $filters = is_array($filters) ? $this->normalizeFilters($filters) : $this->normalizeFilters([]);
            $rows[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'filters' => $filters,
                'updated_at' => $row['updated_at'],
                'live_count' => $this->search($userId, $filters, 250)['count'],
            ];
        }

        return $rows;
    }

    public function savedFilter(string $userId, string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, filter_json, updated_at
             FROM saved_case_filters
             WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $filters = json_decode((string) $row['filter_json'], true, 512, JSON_THROW_ON_ERROR);

        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'filters' => is_array($filters) ? $this->normalizeFilters($filters) : $this->normalizeFilters([]),
            'updated_at' => $row['updated_at'],
        ];
    }

    public function deleteFilter(string $userId, string $id): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM saved_case_filters WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);

        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Gespeicherter Filter nicht gefunden.');
        }
    }

    private function normalizeFilters(array $filters): array
    {
        $normalized = array_fill_keys(self::FILTER_KEYS, null);

        foreach (self::FILTER_KEYS as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            $normalized[$key] = $value === '' ? null : $value;
        }

        foreach (['date_from','date_to'] as $key) {
            if ($normalized[$key] !== null) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $normalized[$key]);
                if (!$date || $date->format('Y-m-d') !== $normalized[$key]) {
                    throw new \InvalidArgumentException('Ungültiges Suchdatum.');
                }
            }
        }

        return $normalized;
    }

    private function decryptNullable(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $this->cipher->decrypt($value);
    }
}
