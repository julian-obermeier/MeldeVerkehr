<?php

declare(strict_types=1);

namespace MeldeVerkehr\Operations;

use MeldeVerkehr\Support\Uuid;

final class ExportStorage
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function store(string $userId, string $extension, string $content): array
    {
        if (!in_array($extension, ['csv','json'], true)) {
            throw new \InvalidArgumentException('Ungültiges Exportformat.');
        }

        if (strlen($content) > 50 * 1024 * 1024) {
            throw new \RuntimeException('Export überschreitet 50 MB.');
        }

        $safeUser = preg_replace('/[^A-Za-z0-9_-]/', '_', $userId) ?: 'user';
        $relativeDir = 'exports/' . $safeUser;
        $absoluteDir = $this->absolute($relativeDir);

        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0700, true) && !is_dir($absoluteDir)) {
            throw new \RuntimeException('Export-Verzeichnis konnte nicht angelegt werden.');
        }

        $relative = $relativeDir . '/' . Uuid::v4() . '.' . $extension;
        $absolute = $this->absolute($relative);

        if (file_put_contents($absolute, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Export konnte nicht gespeichert werden.');
        }

        @chmod($absolute, 0600);

        return [
            'relative_path' => $relative,
            'absolute_path' => $absolute,
            'sha256' => hash('sha256', $content),
            'size' => strlen($content),
        ];
    }

    public function readVerified(string $relativePath, string $expectedSha256): string
    {
        $absolute = $this->absolute($relativePath);

        if (!is_file($absolute) || !is_readable($absolute)) {
            throw new \RuntimeException('Exportdatei fehlt.');
        }

        $content = file_get_contents($absolute);
        if ($content === false) {
            throw new \RuntimeException('Exportdatei konnte nicht gelesen werden.');
        }

        if (!hash_equals($expectedSha256, hash('sha256', $content))) {
            throw new \RuntimeException('Integrität der Exportdatei ist verletzt.');
        }

        return $content;
    }

    public function delete(string $relativePath): void
    {
        $absolute = $this->absolute($relativePath);
        if (is_file($absolute)) {
            @chmod($absolute, 0600);
            @unlink($absolute);
        }
    }

    private function absolute(string $relativePath): string
    {
        $clean = ltrim(str_replace('\\', '/', $relativePath), '/');

        if ($clean === '' || str_contains($clean, '../')) {
            throw new \InvalidArgumentException('Ungültiger Exportpfad.');
        }

        return rtrim($this->basePath, '/\\') . '/' . $clean;
    }
}
