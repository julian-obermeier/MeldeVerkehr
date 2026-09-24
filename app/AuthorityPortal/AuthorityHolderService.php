<?php

declare(strict_types=1);

namespace MeldeVerkehr\AuthorityPortal;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Uuid;
use PDO;

final class AuthorityHolderService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorityAccessService $access,
        private readonly SecretCipher $cipher,
        private readonly AuditLogger $audit
    ) {
    }

    public function save(
        string $userId,
        string $caseId,
        string $name,
        string $address,
        ?string $dateOfBirth = null,
        array $metadata = []
    ): array {
        $caseAccess = $this->access->assertCase(
            $userId,
            $caseId,
            'authority.holder.write'
        );

        $name = trim($name);
        $address = trim($address);

        if ($name === '' || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Haltername ist ungültig.');
        }

        if ($address === '' || mb_strlen($address) > 2000) {
            throw new \InvalidArgumentException('Halteranschrift ist ungültig.');
        }

        $dateOfBirth = $this->normalizeDate($dateOfBirth);
        $metadataJson = json_encode(
            $metadata,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $payload = [
            'name' => $name,
            'address' => $address,
            'date_of_birth' => $dateOfBirth,
            'metadata' => $metadata,
        ];
        $payloadJson = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $authorityId = (string) $caseAccess['authority_id'];

        $stmt = $this->pdo->prepare(
            'SELECT id FROM authority_holder_records
             WHERE authority_id = :authority_id AND case_id = :case_id LIMIT 1'
        );
        $stmt->execute([
            'authority_id' => $authorityId,
            'case_id' => $caseId,
        ]);
        $existing = $stmt->fetchColumn();
        $id = is_string($existing) && $existing !== '' ? $existing : Uuid::v4();

        $this->pdo->prepare(
            'INSERT INTO authority_holder_records
             (id, authority_id, case_id, holder_name_encrypted, holder_address_encrypted,
              date_of_birth_encrypted, metadata_encrypted, payload_sha256, key_version,
              created_by_user_id, created_at, updated_at)
             VALUES
             (:id, :authority_id, :case_id, :name, :address,
              :dob, :metadata, :sha256, 1, :user_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                holder_name_encrypted = VALUES(holder_name_encrypted),
                holder_address_encrypted = VALUES(holder_address_encrypted),
                date_of_birth_encrypted = VALUES(date_of_birth_encrypted),
                metadata_encrypted = VALUES(metadata_encrypted),
                payload_sha256 = VALUES(payload_sha256),
                key_version = key_version + 1,
                created_by_user_id = VALUES(created_by_user_id),
                updated_at = UTC_TIMESTAMP()'
        )->execute([
            'id' => $id,
            'authority_id' => $authorityId,
            'case_id' => $caseId,
            'name' => $this->cipher->encrypt($name),
            'address' => $this->cipher->encrypt($address),
            'dob' => $dateOfBirth === null ? null : $this->cipher->encrypt($dateOfBirth),
            'metadata' => $metadata === [] ? null : $this->cipher->encrypt($metadataJson),
            'sha256' => hash('sha256', $payloadJson),
            'user_id' => $userId,
        ]);

        $this->audit->log(
            'AUTHORITY_HOLDER_RECORD_SAVED',
            'case',
            $caseId,
            'AUTHORITY',
            $userId,
            ['authority_id' => $authorityId]
        );

        return $this->get($userId, $caseId)
            ?? throw new \RuntimeException('Halterdatensatz konnte nicht geladen werden.');
    }

    public function get(string $userId, string $caseId): ?array
    {
        $caseAccess = $this->access->assertCase(
            $userId,
            $caseId,
            'authority.holder.read'
        );

        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM authority_holder_records
             WHERE authority_id = :authority_id AND case_id = :case_id
             LIMIT 1'
        );
        $stmt->execute([
            'authority_id' => $caseAccess['authority_id'],
            'case_id' => $caseId,
        ]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $name = $this->cipher->decrypt((string) $row['holder_name_encrypted']);
        $address = $this->cipher->decrypt((string) $row['holder_address_encrypted']);
        $dob = $row['date_of_birth_encrypted'] === null
            ? null
            : $this->cipher->decrypt((string) $row['date_of_birth_encrypted']);
        $metadata = [];
        if ($row['metadata_encrypted'] !== null) {
            $decoded = json_decode(
                $this->cipher->decrypt((string) $row['metadata_encrypted']),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $metadata = is_array($decoded) ? $decoded : [];
        }

        $payloadJson = json_encode(
            [
                'name' => $name,
                'address' => $address,
                'date_of_birth' => $dob,
                'metadata' => $metadata,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        if (!hash_equals((string) $row['payload_sha256'], hash('sha256', $payloadJson))) {
            throw new \RuntimeException('Integrität des Halterdatensatzes ist verletzt.');
        }

        $this->audit->log(
            'AUTHORITY_HOLDER_RECORD_VIEWED',
            'case',
            $caseId,
            'AUTHORITY',
            $userId,
            ['authority_id' => $caseAccess['authority_id']]
        );

        return [
            'id' => (string) $row['id'],
            'authority_id' => (string) $row['authority_id'],
            'case_id' => (string) $row['case_id'],
            'holder_name' => $name,
            'holder_address' => $address,
            'date_of_birth' => $dob,
            'metadata' => $metadata,
            'key_version' => (int) $row['key_version'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function normalizeDate(?string $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Geburtsdatum ist ungültig.');
        }

        return $value;
    }
}
