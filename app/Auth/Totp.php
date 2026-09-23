<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth;

use MeldeVerkehr\Security\Base32;

final class Totp
{
    public static function secret(): string
    {
        return Base32::encode(random_bytes(20));
    }

    public static function verify(
        string $secret,
        string $code,
        ?int $timestamp = null,
        int $window = 1
    ): bool {
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $timestamp ??= time();
        $counter = intdiv($timestamp, 30);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::code($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $account);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label,
            rawurlencode($secret),
            rawurlencode($issuer)
        );
    }

    private static function code(string $secret, int $counter): string
    {
        $key = Base32::decode($secret);
        $high = intdiv($counter, 0x100000000);
        $low = $counter % 0x100000000;
        $binaryCounter = pack('N2', $high, $low);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = (
            ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }
}
