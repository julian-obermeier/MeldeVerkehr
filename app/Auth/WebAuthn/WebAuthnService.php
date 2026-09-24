<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth\WebAuthn;

use MeldeVerkehr\Support\Uuid;
use PDO;

final class WebAuthnService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $appUrl,
        private readonly string $rpName = 'MeldeVerkehr'
    ) {
    }

    public function registrationOptions(array $user): array
    {
        $challenge = random_bytes(32);
        $this->storeChallenge('register', $challenge, (string) $user['id']);

        return [
            'challenge' => self::b64url($challenge),
            'rp' => [
                'name' => $this->rpName,
                'id' => $this->rpId(),
            ],
            'user' => [
                'id' => self::b64url((string) $user['id']),
                'name' => (string) $user['email'],
                'displayName' => trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
            ],
            'timeout' => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => [
                'residentKey' => 'preferred',
                'userVerification' => 'required',
            ],
        ];
    }

    public function register(string $userId, array $payload): string
    {
        $challenge = $this->consumeChallenge('register', $userId);
        $clientDataJson = self::fromB64url((string) ($payload['clientDataJSON'] ?? ''));
        $clientData = json_decode($clientDataJson, true, 512, JSON_THROW_ON_ERROR);

        $this->validateClientData($clientData, 'webauthn.create', $challenge);

        $attestationObject = self::fromB64url((string) ($payload['attestationObject'] ?? ''));
        $attestation = (new CborDecoder())->decode($attestationObject);

        if (
            !is_array($attestation)
            || ($attestation['fmt'] ?? null) !== 'none'
            || !isset($attestation['authData'])
            || !is_string($attestation['authData'])
        ) {
            throw new \DomainException('Unsupported WebAuthn attestation format.');
        }

        $authenticatorData = $attestation['authData'];
        $this->validateAuthenticatorData($authenticatorData, true);

        $rawId = self::fromB64url((string) ($payload['rawId'] ?? ''));
        [$credentialId, $coseKey, $signCount] = $this->registrationData($authenticatorData);

        if (!hash_equals($credentialId, $rawId)) {
            throw new \DomainException('Credential ID does not match authenticator data.');
        }

        $publicKeyPem = $this->es256Pem($coseKey);
        $credentialIdEncoded = self::b64url($rawId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO passkey_credentials
             (id, user_id, credential_id, credential_id_hash, public_key_pem, algorithm, sign_count, transports_json, label, created_at, last_used_at)
             VALUES (:id, :user_id, :credential_id, :credential_id_hash, :public_key_pem, -7, :sign_count, :transports, :label, UTC_TIMESTAMP(), NULL)'
        );
        $stmt->execute([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'credential_id' => $credentialIdEncoded,
            'credential_id_hash' => hash('sha256', $credentialIdEncoded),
            'public_key_pem' => $publicKeyPem,
            'sign_count' => $signCount,
            'transports' => json_encode($payload['transports'] ?? [], JSON_THROW_ON_ERROR),
            'label' => mb_substr(trim((string) ($payload['label'] ?? 'Passkey')), 0, 120),
        ]);

        return $credentialIdEncoded;
    }

    public function loginOptions(): array
    {
        $challenge = random_bytes(32);
        $this->storeChallenge('login', $challenge, null);

        return [
            'challenge' => self::b64url($challenge),
            'rpId' => $this->rpId(),
            'timeout' => 60000,
            'userVerification' => 'required',
        ];
    }

    public function verifyLogin(array $payload): string
    {
        $challenge = $this->consumeChallenge('login', null);
        $credentialId = (string) ($payload['rawId'] ?? '');

        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, credential_id, public_key_pem, sign_count
             FROM passkey_credentials WHERE credential_id_hash = :credential_id_hash LIMIT 1'
        );
        $stmt->execute(['credential_id_hash' => hash('sha256', $credentialId)]);
        $credential = $stmt->fetch();

        if (!is_array($credential) || !hash_equals((string) $credential['credential_id'], $credentialId)) {
            throw new \DomainException('Unknown passkey credential.');
        }

        $clientDataJson = self::fromB64url((string) ($payload['clientDataJSON'] ?? ''));
        $clientData = json_decode($clientDataJson, true, 512, JSON_THROW_ON_ERROR);
        $this->validateClientData($clientData, 'webauthn.get', $challenge);

        $authenticatorData = self::fromB64url((string) ($payload['authenticatorData'] ?? ''));
        $signCount = $this->validateAuthenticatorData($authenticatorData, false);
        $signature = self::fromB64url((string) ($payload['signature'] ?? ''));

        $signed = $authenticatorData . hash('sha256', $clientDataJson, true);
        $valid = openssl_verify(
            $signed,
            $signature,
            (string) $credential['public_key_pem'],
            OPENSSL_ALGO_SHA256
        );

        if ($valid !== 1) {
            throw new \DomainException('Passkey signature verification failed.');
        }

        $storedCount = (int) $credential['sign_count'];

        if ($storedCount > 0 && $signCount > 0 && $signCount <= $storedCount) {
            throw new \DomainException('Passkey signature counter is invalid.');
        }

        $update = $this->pdo->prepare(
            'UPDATE passkey_credentials
             SET sign_count = :sign_count, last_used_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $update->execute([
            'sign_count' => max($storedCount, $signCount),
            'id' => $credential['id'],
        ]);

        return (string) $credential['user_id'];
    }

    public function listForUser(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, credential_id, label, created_at, last_used_at
             FROM passkey_credentials WHERE user_id = :user_id ORDER BY created_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function delete(string $userId, string $id): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM passkey_credentials WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);

        return $stmt->rowCount() === 1;
    }

    private function storeChallenge(string $purpose, string $challenge, ?string $userId): void
    {
        $_SESSION['webauthn_challenges'][$purpose] = [
            'value' => self::b64url($challenge),
            'user_id' => $userId,
            'expires_at' => time() + 120,
        ];
    }

    private function consumeChallenge(string $purpose, ?string $userId): string
    {
        $stored = $_SESSION['webauthn_challenges'][$purpose] ?? null;
        unset($_SESSION['webauthn_challenges'][$purpose]);

        if (
            !is_array($stored)
            || (int) ($stored['expires_at'] ?? 0) < time()
            || ($stored['user_id'] ?? null) !== $userId
        ) {
            throw new \DomainException('Passkey challenge is invalid or expired.');
        }

        return (string) $stored['value'];
    }

    private function validateClientData(array $data, string $type, string $challenge): void
    {
        if (($data['type'] ?? null) !== $type) {
            throw new \DomainException('Unexpected WebAuthn operation type.');
        }

        if (!is_string($data['challenge'] ?? null) || !hash_equals($challenge, (string) $data['challenge'])) {
            throw new \DomainException('WebAuthn challenge mismatch.');
        }

        if (($data['origin'] ?? null) !== $this->origin()) {
            throw new \DomainException('WebAuthn origin mismatch.');
        }

        if (($data['crossOrigin'] ?? false) === true) {
            throw new \DomainException('Cross-origin WebAuthn requests are not accepted.');
        }
    }

    private function validateAuthenticatorData(string $data, bool $registration): int
    {
        if (strlen($data) < 37) {
            throw new \DomainException('Authenticator data is too short.');
        }

        $rpHash = substr($data, 0, 32);
        if (!hash_equals(hash('sha256', $this->rpId(), true), $rpHash)) {
            throw new \DomainException('WebAuthn RP ID hash mismatch.');
        }

        $flags = ord($data[32]);
        if (($flags & 0x01) !== 0x01) {
            throw new \DomainException('User presence was not verified.');
        }

        if (($flags & 0x04) !== 0x04) {
            throw new \DomainException('User verification is required.');
        }

        if ($registration && ($flags & 0x40) !== 0x40) {
            throw new \DomainException('Attested credential data is missing.');
        }

        return unpack('N', substr($data, 33, 4))[1];
    }

    private function registrationData(string $data): array
    {
        $offset = 37 + 16;
        $length = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;

        if ($length < 16 || $offset + $length >= strlen($data)) {
            throw new \DomainException('Invalid WebAuthn credential ID length.');
        }

        $credentialId = substr($data, $offset, $length);
        $offset += $length;
        $coseBytes = substr($data, $offset);
        $cose = (new CborDecoder())->decode($coseBytes);

        if (!is_array($cose)) {
            throw new \DomainException('Invalid WebAuthn COSE key.');
        }

        $signCount = unpack('N', substr($data, 33, 4))[1];

        return [$credentialId, $cose, $signCount];
    }

    private function es256Pem(array $cose): string
    {
        $kty = (int) ($cose['1'] ?? 0);
        $alg = (int) ($cose['3'] ?? 0);
        $crv = (int) ($cose['-1'] ?? 0);
        $x = $cose['-2'] ?? null;
        $y = $cose['-3'] ?? null;

        if ($kty !== 2 || $alg !== -7 || $crv !== 1 || !is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            throw new \DomainException('Only ES256 P-256 passkeys are supported.');
        }

        $prefix = hex2bin('3059301306072A8648CE3D020106082A8648CE3D03010703420004');
        if ($prefix === false) {
            throw new \RuntimeException('Could not build EC public key.');
        }

        $der = $prefix . $x . $y;

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function rpId(): string
    {
        $host = parse_url($this->appUrl, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('APP_URL must contain a valid host for WebAuthn.');
        }

        return strtolower($host);
    }

    private function origin(): string
    {
        $parts = parse_url($this->appUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('APP_URL is invalid.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if ($scheme !== 'https' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new \RuntimeException('WebAuthn requires HTTPS outside localhost.');
        }

        $origin = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin;
    }

    public static function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function fromB64url(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);

        if ($decoded === false) {
            throw new \DomainException('Invalid Base64URL value.');
        }

        return $decoded;
    }
}
