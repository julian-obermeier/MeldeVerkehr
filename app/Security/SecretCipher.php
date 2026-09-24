<?php

declare(strict_types=1);

namespace MeldeVerkehr\Security;

final class SecretCipher
{
    private readonly string $key;

    public function __construct(string $appKey)
    {
        if ($appKey === '') {
            throw new \InvalidArgumentException('Application key is required for encryption.');
        }

        $this->key = hash('sha256', $appKey, true);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Could not encrypt secret.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        $payload = base64_decode($encoded, true);

        if ($payload === false || strlen($payload) < 28) {
            throw new \RuntimeException('Encrypted secret is invalid.');
        }

        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ciphertext = substr($payload, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Could not decrypt secret.');
        }

        return $plaintext;
    }
}
