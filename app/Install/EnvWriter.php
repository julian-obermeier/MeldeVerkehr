<?php

declare(strict_types=1);

namespace MeldeVerkehr\Install;

final class EnvWriter
{
    public function write(string $path, array $values): void
    {
        $lines = [];

        foreach ($values as $key => $value) {
            if (!preg_match('/^[A-Z0-9_]+$/', (string) $key)) {
                throw new \InvalidArgumentException('Invalid environment key.');
            }

            $lines[] = $key . '=' . $this->quote((string) $value);
        }

        $content = implode(PHP_EOL, $lines) . PHP_EOL;
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));

        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write temporary environment file.');
        }

        @chmod($temporary, 0600);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Could not replace environment file.');
        }

        @chmod($path, 0600);
    }

    private function quote(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (preg_match('/^[A-Za-z0-9_\.\-:\/]+$/', $value)) {
            return $value;
        }

        return '"' . addcslashes($value, "\\\"\r\n") . '"';
    }
}
