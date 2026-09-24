<?php

declare(strict_types=1);

namespace MeldeVerkehr\Evidence;

final class EvidenceImageProcessor
{
    public function __construct(
        private readonly EvidenceStorage $storage,
        private readonly int $maxDimension = 1920
    ) {
    }

    public function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    public function createWorkingCopy(
        string $caseId,
        string $evidenceId,
        string $originalRelativePath,
        string $mimeType
    ): ?array {
        if (!$this->available()) {
            return null;
        }

        $sourcePath = $this->storage->absolute($originalRelativePath);
        $image = $this->load($sourcePath, $mimeType);

        if (!$image instanceof \GdImage) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 1 || $height < 1) {
            imagedestroy($image);
            return null;
        }

        $scale = min(1, $this->maxDimension / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$target instanceof \GdImage) {
            imagedestroy($image);
            return null;
        }

        if (in_array($mimeType, ['image/png', 'image/webp'], true)) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
        }

        imagecopyresampled(
            $target,
            $image,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        );

        $temp = tempnam(sys_get_temp_dir(), 'mv-working-');
        if ($temp === false) {
            imagedestroy($target);
            imagedestroy($image);
            throw new \RuntimeException('Temporäre Arbeitskopie konnte nicht angelegt werden.');
        }

        $saved = match ($mimeType) {
            'image/jpeg' => imagejpeg($target, $temp, 85),
            'image/png' => imagepng($target, $temp, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($target, $temp, 82) : false,
            default => false,
        };

        imagedestroy($target);
        imagedestroy($image);

        if (!$saved) {
            @unlink($temp);
            return null;
        }

        try {
            $stored = $this->storage->storeVariant(
                $caseId,
                $evidenceId,
                'WORKING',
                1,
                $temp,
                $mimeType
            );
        } finally {
            @unlink($temp);
        }

        return array_merge($stored, [
            'mime_type' => $mimeType,
            'width' => $targetWidth,
            'height' => $targetHeight,
            'processing' => [
                'operation' => 'resize',
                'max_dimension' => $this->maxDimension,
                'source_width' => $width,
                'source_height' => $height,
            ],
        ]);
    }

    private function load(string $path, string $mimeType): \GdImage|false
    {
        return match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }
}
