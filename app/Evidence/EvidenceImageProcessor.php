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

    public function createPublicCopy(
        string $caseId,
        string $evidenceId,
        string $sourceRelativePath,
        string $mimeType,
        array $regions,
        int $version
    ): array {
        if (!$this->available()) {
            throw new \RuntimeException('GD ist für Privacy-Redaktionen erforderlich.');
        }

        $sourcePath = $this->storage->absolute($sourceRelativePath);
        $image = $this->load($sourcePath, $mimeType);

        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('Arbeitskopie konnte nicht für Privacy-Redaktion geöffnet werden.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $redaction = imagecolorallocate($image, 0, 0, 0);

        foreach ($regions as $region) {
            $x1 = max(0, min($width - 1, (int) floor(((float) $region['x']) * $width)));
            $y1 = max(0, min($height - 1, (int) floor(((float) $region['y']) * $height)));
            $x2 = max($x1, min($width - 1, (int) ceil((((float) $region['x']) + ((float) $region['width'])) * $width)));
            $y2 = max($y1, min($height - 1, (int) ceil((((float) $region['y']) + ((float) $region['height'])) * $height)));
            imagefilledrectangle($image, $x1, $y1, $x2, $y2, $redaction);
        }

        $temp = tempnam(sys_get_temp_dir(), 'mv-public-');
        if ($temp === false) {
            imagedestroy($image);
            throw new \RuntimeException('Temporäre Privacy-Kopie konnte nicht angelegt werden.');
        }

        $saved = match ($mimeType) {
            'image/jpeg' => imagejpeg($image, $temp, 85),
            'image/png' => imagepng($image, $temp, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, $temp, 82) : false,
            default => false,
        };
        imagedestroy($image);

        if (!$saved) {
            @unlink($temp);
            throw new \RuntimeException('Privacy-Kopie konnte nicht erzeugt werden.');
        }

        try {
            $stored = $this->storage->storeVariant(
                $caseId,
                $evidenceId,
                'PUBLIC',
                $version,
                $temp,
                $mimeType
            );
        } finally {
            @unlink($temp);
        }

        return array_merge($stored, [
            'mime_type' => $mimeType,
            'width' => $width,
            'height' => $height,
            'processing' => [
                'operation' => 'privacy_redaction',
                'region_count' => count($regions),
                'region_types' => array_values(array_unique(array_map(
                    static fn(array $region): string => (string) ($region['region_type'] ?? 'OTHER'),
                    $regions
                ))),
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
