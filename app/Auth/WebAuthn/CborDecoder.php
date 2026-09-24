<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth\WebAuthn;

final class CborDecoder
{
    private int $offset = 0;

    public function decode(string $data): mixed
    {
        $this->offset = 0;
        return $this->item($data);
    }

    private function item(string $data): mixed
    {
        if ($this->offset >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of CBOR data.');
        }

        $initial = ord($data[$this->offset++]);
        $major = $initial >> 5;
        $additional = $initial & 0x1f;
        $length = $this->length($data, $additional);

        return match ($major) {
            0 => $length,
            1 => -1 - $length,
            2 => $this->bytes($data, $length),
            3 => $this->bytes($data, $length),
            4 => $this->arrayValue($data, $length),
            5 => $this->mapValue($data, $length),
            default => throw new \RuntimeException('Unsupported CBOR major type: ' . $major),
        };
    }

    private function length(string $data, int $additional): int
    {
        if ($additional < 24) {
            return $additional;
        }

        return match ($additional) {
            24 => ord($this->read($data, 1)),
            25 => unpack('n', $this->read($data, 2))[1],
            26 => unpack('N', $this->read($data, 4))[1],
            default => throw new \RuntimeException('Unsupported CBOR length encoding.'),
        };
    }

    private function read(string $data, int $length): string
    {
        if ($this->offset + $length > strlen($data)) {
            throw new \RuntimeException('CBOR value exceeds input.');
        }

        $value = substr($data, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }

    private function bytes(string $data, int $length): string
    {
        return $this->read($data, $length);
    }

    private function arrayValue(string $data, int $length): array
    {
        $items = [];

        for ($i = 0; $i < $length; $i++) {
            $items[] = $this->item($data);
        }

        return $items;
    }

    private function mapValue(string $data, int $length): array
    {
        $map = [];

        for ($i = 0; $i < $length; $i++) {
            $key = $this->item($data);
            $map[(string) $key] = $this->item($data);
        }

        return $map;
    }
}
