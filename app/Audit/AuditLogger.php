<?php

declare(strict_types=1);

namespace MeldeVerkehr\Audit;

use PDO;
use Throwable;

final class AuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function log(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        string $actorType = 'SYSTEM',
        ?string $actorId = null,
        array $metadata = []
    ): string {
        $createdAt = gmdate('Y-m-d H:i:s');
        $metadataJson = $metadata === []
            ? null
            : json_encode($this->normalize($metadata), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            $this->pdo->beginTransaction();

            $state = $this->pdo->query(
                'SELECT current_hash FROM audit_state WHERE id = 1 FOR UPDATE'
            )->fetch();

            if (!is_array($state)) {
                throw new \RuntimeException('Audit state row is missing.');
            }

            $previousHash = $state['current_hash'] !== null ? (string) $state['current_hash'] : null;
            $entryHash = hash('sha256', implode('|', [
                $previousHash ?? '',
                $actorType,
                $actorId ?? '',
                $action,
                $entityType ?? '',
                $entityId ?? '',
                $metadataJson ?? '',
                $createdAt,
            ]));

            $stmt = $this->pdo->prepare(
                'INSERT INTO audit_logs (
                    actor_type, actor_id, action, entity_type, entity_id,
                    metadata_json, previous_hash, entry_hash, created_at
                 ) VALUES (
                    :actor_type, :actor_id, :action, :entity_type, :entity_id,
                    :metadata_json, :previous_hash, :entry_hash, :created_at
                 )'
            );
            $stmt->execute([
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'metadata_json' => $metadataJson,
                'previous_hash' => $previousHash,
                'entry_hash' => $entryHash,
                'created_at' => $createdAt,
            ]);

            $update = $this->pdo->prepare(
                'UPDATE audit_state SET current_hash = :hash, updated_at = UTC_TIMESTAMP() WHERE id = 1'
            );
            $update->execute(['hash' => $entryHash]);

            $this->pdo->commit();

            return $entryHash;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function verifyChain(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, actor_type, actor_id, action, entity_type, entity_id,
                    metadata_json, previous_hash, entry_hash, created_at
             FROM audit_logs ORDER BY id ASC'
        )->fetchAll();

        $previous = null;

        foreach ($rows as $row) {
            if (($row['previous_hash'] ?? null) !== $previous) {
                return ['ok' => false, 'broken_at_id' => (int) $row['id']];
            }

            $expected = hash('sha256', implode('|', [
                $previous ?? '',
                (string) $row['actor_type'],
                (string) ($row['actor_id'] ?? ''),
                (string) $row['action'],
                (string) ($row['entity_type'] ?? ''),
                (string) ($row['entity_id'] ?? ''),
                (string) ($row['metadata_json'] ?? ''),
                (string) $row['created_at'],
            ]));

            if (!hash_equals((string) $row['entry_hash'], $expected)) {
                return ['ok' => false, 'broken_at_id' => (int) $row['id']];
            }

            $previous = (string) $row['entry_hash'];
        }

        return ['ok' => true, 'broken_at_id' => null];
    }

    private function normalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalize($item);
            }
        }

        ksort($value);

        return $value;
    }
}
