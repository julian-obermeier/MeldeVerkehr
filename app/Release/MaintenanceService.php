<?php

declare(strict_types=1);

namespace MeldeVerkehr\Release;

final class MaintenanceService
{
    public function __construct(private readonly string $flagPath)
    {
    }

    public function enable(string $reason = 'Systemwartung'): void
    {
        $directory = dirname($this->flagPath);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Maintenance-Verzeichnis konnte nicht erstellt werden.');
        }

        $payload = json_encode([
            'enabled_at' => gmdate('c'),
            'reason' => trim($reason) === '' ? 'Systemwartung' : trim($reason),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (file_put_contents($this->flagPath, $payload, LOCK_EX) === false) {
            throw new \RuntimeException('Maintenance-Modus konnte nicht aktiviert werden.');
        }
    }

    public function disable(): void
    {
        if (is_file($this->flagPath) && !unlink($this->flagPath)) {
            throw new \RuntimeException('Maintenance-Modus konnte nicht deaktiviert werden.');
        }
    }

    public function active(): bool
    {
        return is_file($this->flagPath);
    }

    public function status(): array
    {
        if (!$this->active()) {
            return ['active' => false, 'enabled_at' => null, 'reason' => null];
        }

        $raw = @file_get_contents($this->flagPath);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return [
            'active' => true,
            'enabled_at' => is_array($data) ? ($data['enabled_at'] ?? null) : null,
            'reason' => is_array($data) ? ($data['reason'] ?? 'Systemwartung') : 'Systemwartung',
        ];
    }
}
