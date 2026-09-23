<?php

declare(strict_types=1);

namespace MeldeVerkehr\Security;

final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $data): string
    {
        $bits = '';

        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= self::ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    public static function decode(string $value): string
    {
        $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value) ?? '');
        $bits = '';

        foreach (str_split($value) as $char) {
            $position = strpos(self::ALPHABET, $char);

            if ($position === false) {
                throw new \InvalidArgumentException('Invalid Base32 data.');
            }

            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }

            $decoded .= chr(bindec($chunk));
        }

        return $decoded;
    }
}
