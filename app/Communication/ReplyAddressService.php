<?php

declare(strict_types=1);

namespace MeldeVerkehr\Communication;

use MeldeVerkehr\Support\Uuid;
use PDO;

final class ReplyAddressService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $domain,
        private readonly string $localPrefix,
        private readonly string $hashKey
    ) {
    }

    public function create(string $caseId, ?string $dispatchId = null): array
    {
        $domain = strtolower(trim($this->domain));
        $prefix = strtolower(trim($this->localPrefix));

        if (
            $domain === ''
            || !preg_match('/^[a-z0-9.-]+$/', $domain)
            || $prefix === ''
            || !preg_match('/^[a-z0-9._-]+$/', $prefix)
        ) {
            throw new \RuntimeException('Reply-Adresse ist nicht korrekt konfiguriert.');
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = bin2hex(random_bytes(16));
            $local = $prefix . '+' . $token;
            $full = $local . '@' . $domain;

            try {
                $id = Uuid::v4();
                $stmt = $this->pdo->prepare(
                    'INSERT INTO case_reply_addresses
                     (id, case_id, dispatch_id, local_part, full_address, token_hash, status, created_at, disabled_at)
                     VALUES
                     (:id, :case_id, :dispatch_id, :local_part, :full_address, :token_hash, "ACTIVE", UTC_TIMESTAMP(), NULL)'
                );
                $stmt->execute([
                    'id' => $id,
                    'case_id' => $caseId,
                    'dispatch_id' => $dispatchId,
                    'local_part' => $local,
                    'full_address' => $full,
                    'token_hash' => hash_hmac('sha256', $token, $this->hashKey),
                ]);

                return [
                    'id' => $id,
                    'case_id' => $caseId,
                    'dispatch_id' => $dispatchId,
                    'address' => $full,
                    'local_part' => $local,
                ];
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Eindeutige Reply-Adresse konnte nicht erzeugt werden.');
    }

    public function attachToDispatch(string $replyAddressId, string $dispatchId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE case_reply_addresses
             SET dispatch_id = :dispatch_id
             WHERE id = :id AND status = "ACTIVE"'
        );
        $stmt->execute([
            'dispatch_id' => $dispatchId,
            'id' => $replyAddressId,
        ]);
    }

    public function resolve(string $recipientAddress): ?array
    {
        $address = strtolower(trim($recipientAddress));

        $stmt = $this->pdo->prepare(
            'SELECT id, case_id, dispatch_id, full_address
             FROM case_reply_addresses
             WHERE LOWER(full_address) = :address AND status = "ACTIVE"
             LIMIT 1'
        );
        $stmt->execute(['address' => $address]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
