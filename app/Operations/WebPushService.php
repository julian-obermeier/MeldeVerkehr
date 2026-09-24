<?php

declare(strict_types=1);

namespace MeldeVerkehr\Operations;

use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Uuid;
use PDO;

final class WebPushService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SecretCipher $cipher,
        private readonly string $publicKey,
        private readonly string $privateKey,
        private readonly string $subject,
        private readonly int $ttl = 120
    ) {
    }

    public function configured(): bool
    {
        return $this->publicKey !== ''
            && $this->privateKey !== ''
            && ($this->subject !== '')
            && function_exists('curl_init')
            && function_exists('openssl_sign');
    }

    public function publicKey(): string
    {
        return $this->configured() ? $this->publicKey : '';
    }

    public function register(
        string $userId,
        string $endpoint,
        ?string $p256dh = null,
        ?string $auth = null,
        ?string $userAgent = null
    ): void {
        $endpoint = trim($endpoint);
        if (
            $endpoint === ''
            || strlen($endpoint) > 2000
            || !filter_var($endpoint, FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)) !== 'https'
        ) {
            throw new \InvalidArgumentException('Push-Endpunkt ist ungültig.');
        }

        $hash = hash('sha256', $endpoint);

        $this->pdo->prepare(
            'INSERT INTO web_push_subscriptions
             (id, user_id, endpoint_encrypted, endpoint_hash, p256dh, auth_secret,
              status, user_agent, created_at, updated_at, last_success_at)
             VALUES
             (:id, :user_id, :endpoint, :endpoint_hash, :p256dh, :auth_secret,
              "ACTIVE", :user_agent, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)
             ON DUPLICATE KEY UPDATE
                endpoint_encrypted = VALUES(endpoint_encrypted),
                p256dh = VALUES(p256dh),
                auth_secret = VALUES(auth_secret),
                status = "ACTIVE",
                user_agent = VALUES(user_agent),
                updated_at = UTC_TIMESTAMP()'
        )->execute([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'endpoint' => $this->cipher->encrypt($endpoint),
            'endpoint_hash' => $hash,
            'p256dh' => $this->nullableLimited($p256dh, 255),
            'auth_secret' => $this->nullableLimited($auth, 255),
            'user_agent' => $this->nullableLimited($userAgent, 500),
        ]);
    }

    public function revokeAll(string $userId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE web_push_subscriptions
             SET status = "REVOKED", updated_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND status = "ACTIVE"'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->rowCount();
    }

    public function activeCount(string $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM web_push_subscriptions
             WHERE user_id = :user_id AND status = "ACTIVE"'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function sendSignal(string $userId): bool
    {
        if (!$this->configured()) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, endpoint_encrypted
             FROM web_push_subscriptions
             WHERE user_id = :user_id AND status = "ACTIVE"
             ORDER BY updated_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return false;
        }

        $sent = false;
        foreach ($rows as $row) {
            $endpoint = $this->cipher->decrypt((string) $row['endpoint_encrypted']);
            $result = $this->sendEndpoint($endpoint);

            if ($result['ok']) {
                $sent = true;
                $this->pdo->prepare(
                    'UPDATE web_push_subscriptions
                     SET last_success_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                     WHERE id = :id'
                )->execute(['id' => $row['id']]);
                continue;
            }

            if (in_array($result['status'], [404, 410], true)) {
                $this->pdo->prepare(
                    'UPDATE web_push_subscriptions
                     SET status = "EXPIRED", updated_at = UTC_TIMESTAMP()
                     WHERE id = :id'
                )->execute(['id' => $row['id']]);
            }
        }

        return $sent;
    }

    private function sendEndpoint(string $endpoint): array
    {
        $origin = $this->origin($endpoint);
        if ($origin === null) {
            return ['ok' => false, 'status' => 0];
        }

        $jwt = $this->vapidJwt($origin);
        if ($jwt === null) {
            return ['ok' => false, 'status' => 0];
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'TTL: ' . max(0, min(86400, $this->ttl)),
                'Content-Length: 0',
                'Authorization: vapid t=' . $jwt . ', k=' . $this->publicKey,
            ],
        ]);

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
        ];
    }

    private function vapidJwt(string $audience): ?string
    {
        $subject = trim($this->subject);
        if (
            !str_starts_with($subject, 'mailto:')
            && !str_starts_with($subject, 'https://')
        ) {
            return null;
        }

        $header = $this->b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $payload = $this->b64url(json_encode([
            'aud' => $audience,
            'exp' => time() + 43200,
            'sub' => $subject,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $input = $header . '.' . $payload;

        $pem = str_replace('\\n', "\n", trim($this->privateKey));
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            return null;
        }

        $der = '';
        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $raw = $this->derSignatureToJose($der);
        if ($raw === null) {
            return null;
        }

        return $input . '.' . $this->b64url($raw);
    }

    private function derSignatureToJose(string $der): ?string
    {
        $offset = 0;
        if ($this->byte($der, $offset++) !== 0x30) {
            return null;
        }
        if ($this->readLength($der, $offset) === null) {
            return null;
        }
        if ($this->byte($der, $offset++) !== 0x02) {
            return null;
        }
        $rLength = $this->readLength($der, $offset);
        if ($rLength === null) {
            return null;
        }
        $r = substr($der, $offset, $rLength);
        $offset += $rLength;

        if ($this->byte($der, $offset++) !== 0x02) {
            return null;
        }
        $sLength = $this->readLength($der, $offset);
        if ($sLength === null) {
            return null;
        }
        $s = substr($der, $offset, $sLength);

        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        if (strlen($r) !== 32 || strlen($s) !== 32) {
            return null;
        }

        return $r . $s;
    }

    private function readLength(string $der, int &$offset): ?int
    {
        $first = $this->byte($der, $offset++);
        if ($first === null) {
            return null;
        }
        if (($first & 0x80) === 0) {
            return $first;
        }

        $count = $first & 0x7f;
        if ($count < 1 || $count > 4) {
            return null;
        }

        $length = 0;
        for ($i = 0; $i < $count; $i++) {
            $byte = $this->byte($der, $offset++);
            if ($byte === null) {
                return null;
            }
            $length = ($length << 8) | $byte;
        }

        return $length;
    }

    private function byte(string $value, int $offset): ?int
    {
        return isset($value[$offset]) ? ord($value[$offset]) : null;
    }

    private function origin(string $endpoint): ?string
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $origin = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin;
    }

    private function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function nullableLimited(?string $value, int $length): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
