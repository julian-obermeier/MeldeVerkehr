<?php

declare(strict_types=1);

namespace MeldeVerkehr\Communication;

use MeldeVerkehr\Support\Uuid;

final class CommunicationStorage
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function storeAttachment(
        string $caseId,
        string $messageId,
        string $filename,
        string $mimeType,
        string $content
    ): array {
        if (strlen($content) > 25 * 1024 * 1024) {
            throw new \RuntimeException('Eingehender Anhang überschreitet 25 MB.');
        }

        $extension = $this->extension($filename, $mimeType);
        $relative = sprintf(
            'communications/%s/%s/%s%s',
            $this->safeId($caseId),
            $this->safeId($messageId),
            Uuid::v4(),
            $extension === '' ? '' : '.' . $extension
        );
        $absolute = $this->absolute($relative);
        $directory = dirname($absolute);

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Kommunikationsspeicher konnte nicht angelegt werden.');
        }

        if (file_put_contents($absolute, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Eingehender Anhang konnte nicht gespeichert werden.');
        }

        @chmod($absolute, 0600);

        return [
            'relative_path' => $relative,
            'sha256' => hash('sha256', $content),
            'size' => strlen($content),
            'mime_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            'original_filename' => $this->safeOriginalFilename($filename),
        ];
    }

    public function absolute(string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        if (str_contains($relativePath, '../')) {
            throw new \InvalidArgumentException('Ungültiger Kommunikationspfad.');
        }

        return rtrim($this->basePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function extension(string $filename, string $mime): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== '' && preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
            return $ext;
        }

        return match (strtolower($mime)) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'text/plain' => 'txt',
            default => '',
        };
    }

    private function safeId(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $id) ?: 'unknown';
    }

    private function safeOriginalFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]+/u', '', $filename) ?? 'anlage';

        return mb_substr($filename === '' ? 'anlage' : $filename, 0, 255);
    }
}
