<?php

declare(strict_types=1);

namespace MeldeVerkehr\Evidence;

final class EvidenceStorage
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly string $basePath)
    {
    }

    public function storeOriginal(
        string $caseId,
        string $evidenceId,
        string $sourcePath,
        string $mimeType
    ): array {
        $extension = self::EXTENSIONS[$mimeType] ?? null;

        if ($extension === null) {
            throw new \InvalidArgumentException('Nicht unterstützter Dateityp.');
        }

        $relativeDirectory = 'evidence/originals/' . $caseId;
        $absoluteDirectory = $this->absolute($relativeDirectory);

        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0770, true) && !is_dir($absoluteDirectory)) {
            throw new \RuntimeException('Evidence-Verzeichnis konnte nicht angelegt werden.');
        }

        $relativePath = $relativeDirectory . '/' . $evidenceId . '.' . $extension;
        $absolutePath = $this->absolute($relativePath);

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \InvalidArgumentException('Quelldatei ist nicht lesbar.');
        }

        if (!copy($sourcePath, $absolutePath)) {
            throw new \RuntimeException('Originaldatei konnte nicht gespeichert werden.');
        }

        @chmod($absolutePath, 0440);

        return [
            'relative_path' => $relativePath,
            'absolute_path' => $absolutePath,
            'sha256' => hash_file('sha256', $absolutePath),
            'size' => filesize($absolutePath) ?: 0,
        ];
    }

    public function storeVariant(
        string $caseId,
        string $evidenceId,
        string $variant,
        int $version,
        string $sourcePath,
        string $mimeType
    ): array {
        $extension = self::EXTENSIONS[$mimeType] ?? null;

        if ($extension === null) {
            throw new \InvalidArgumentException('Nicht unterstützter Dateityp.');
        }

        $variantDirectory = strtolower($variant);
        if (!in_array($variantDirectory, ['working', 'public'], true)) {
            throw new \InvalidArgumentException('Ungültige Evidence-Variante.');
        }

        $relativeDirectory = 'evidence/' . $variantDirectory . '/' . $caseId;
        $absoluteDirectory = $this->absolute($relativeDirectory);

        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0770, true) && !is_dir($absoluteDirectory)) {
            throw new \RuntimeException('Evidence-Verzeichnis konnte nicht angelegt werden.');
        }

        $relativePath = sprintf(
            '%s/%s-v%d.%s',
            $relativeDirectory,
            $evidenceId,
            $version,
            $extension
        );
        $absolutePath = $this->absolute($relativePath);

        if (!copy($sourcePath, $absolutePath)) {
            throw new \RuntimeException('Evidence-Variante konnte nicht gespeichert werden.');
        }

        @chmod($absolutePath, 0440);

        return [
            'relative_path' => $relativePath,
            'absolute_path' => $absolutePath,
            'sha256' => hash_file('sha256', $absolutePath),
            'size' => filesize($absolutePath) ?: 0,
        ];
    }

    public function absolute(string $relativePath): string
    {
        $clean = str_replace('\\', '/', $relativePath);

        if (
            $clean === ''
            || str_starts_with($clean, '/')
            || str_contains($clean, '../')
            || str_contains($clean, '..\\')
        ) {
            throw new \InvalidArgumentException('Ungültiger Storage-Pfad.');
        }

        return rtrim($this->basePath, '/\\') . '/' . ltrim($clean, '/');
    }

    public function deletePhysical(string $relativePath): void
    {
        $absolute = $this->absolute($relativePath);

        if (is_file($absolute)) {
            @chmod($absolute, 0660);
            @unlink($absolute);
        }
    }

    public function hash(string $relativePath): string
    {
        $absolute = $this->absolute($relativePath);

        if (!is_file($absolute)) {
            throw new \RuntimeException('Evidence-Datei fehlt.');
        }

        return hash_file('sha256', $absolute);
    }
}
