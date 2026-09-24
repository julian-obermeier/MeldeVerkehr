<?php

declare(strict_types=1);

namespace MeldeVerkehr\Update;

final class MaintenanceModeService
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function enable(string $reason = 'Systemupdate'): array
    {
        $directory = $this->basePath . '/storage/app';

        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Maintenance-Verzeichnis konnte nicht angelegt werden.');
        }

        $payload = [
            'enabled_at' => gmdate(DATE_ATOM),
            'reason' => mb_substr(trim($reason), 0, 500),
            'nonce' => bin2hex(random_bytes(16)),
        ];

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        if (file_put_contents($this->path(), $json, LOCK_EX) === false) {
            throw new \RuntimeException('Maintenance-Mode konnte nicht aktiviert werden.');
        }

        @chmod($this->path(), 0600);

        return $payload;
    }

    public function disable(): void
    {
        if (is_file($this->path()) && !@unlink($this->path())) {
            throw new \RuntimeException('Maintenance-Mode konnte nicht deaktiviert werden.');
        }
    }

    public function enabled(): bool
    {
        return is_file($this->path());
    }

    public function status(): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $json = file_get_contents($this->path());
        if ($json === false) {
            return ['enabled_at' => null, 'reason' => 'Maintenance'];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded)
            ? $decoded
            : ['enabled_at' => null, 'reason' => 'Maintenance'];
    }

    private function path(): string
    {
        return $this->basePath . '/storage/app/maintenance.json';
    }
}
