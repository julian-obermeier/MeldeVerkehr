<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth;

use MeldeVerkehr\Security\SecretCipher;
use PDO;

final class TwoFactorService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SecretCipher $cipher
    ) {
    }

    public function enabled(string $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT confirmed_at FROM user_totp WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    public function beginSetup(string $userId, string $email, string $issuer): array
    {
        $secret = Totp::secret();
        $encrypted = $this->cipher->encrypt($secret);

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_totp (user_id, secret_encrypted, confirmed_at, recovery_codes_json, updated_at)
             VALUES (:user_id, :secret, NULL, NULL, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               secret_encrypted = VALUES(secret_encrypted),
               confirmed_at = NULL,
               recovery_codes_json = NULL,
               updated_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([
            'user_id' => $userId,
            'secret' => $encrypted,
        ]);

        return [
            'secret' => $secret,
            'uri' => Totp::uri($secret, $email, $issuer),
        ];
    }

    public function confirm(string $userId, string $code): ?array
    {
        $secret = $this->secretFor($userId, false);

        if ($secret === null || !Totp::verify($secret, $code)) {
            return null;
        }

        $plainCodes = [];
        $hashes = [];

        for ($i = 0; $i < 8; $i++) {
            $plain = strtoupper(bin2hex(random_bytes(5)));
            $plainCodes[] = substr($plain, 0, 5) . '-' . substr($plain, 5);
            $hashes[] = password_hash(str_replace('-', '', $plainCodes[$i]), PASSWORD_DEFAULT);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE user_totp
             SET confirmed_at = UTC_TIMESTAMP(), recovery_codes_json = :codes, updated_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id'
        );
        $stmt->execute([
            'codes' => json_encode($hashes, JSON_THROW_ON_ERROR),
            'user_id' => $userId,
        ]);

        return $plainCodes;
    }

    public function verify(string $userId, string $code): bool
    {
        $secret = $this->secretFor($userId, true);

        if ($secret !== null && Totp::verify($secret, preg_replace('/\s+/', '', $code) ?? $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($userId, $code);
    }

    public function disable(string $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_totp WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }

    private function secretFor(string $userId, bool $confirmedOnly): ?string
    {
        $sql = 'SELECT secret_encrypted FROM user_totp WHERE user_id = :user_id';
        if ($confirmedOnly) {
            $sql .= ' AND confirmed_at IS NOT NULL';
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $encrypted = $stmt->fetchColumn();

        return is_string($encrypted) ? $this->cipher->decrypt($encrypted) : null;
    }

    private function consumeRecoveryCode(string $userId, string $code): bool
    {
        $normalized = strtoupper(str_replace(['-', ' '], '', $code));

        if ($normalized === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT recovery_codes_json FROM user_totp
             WHERE user_id = :user_id AND confirmed_at IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $json = $stmt->fetchColumn();

        if (!is_string($json)) {
            return false;
        }

        $hashes = json_decode($json, true);

        if (!is_array($hashes)) {
            return false;
        }

        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && password_verify($normalized, $hash)) {
                unset($hashes[$index]);
                $update = $this->pdo->prepare(
                    'UPDATE user_totp SET recovery_codes_json = :codes, updated_at = UTC_TIMESTAMP()
                     WHERE user_id = :user_id'
                );
                $update->execute([
                    'codes' => json_encode(array_values($hashes), JSON_THROW_ON_ERROR),
                    'user_id' => $userId,
                ]);

                return true;
            }
        }

        return false;
    }
}
