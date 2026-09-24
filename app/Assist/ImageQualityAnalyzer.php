<?php

declare(strict_types=1);

namespace MeldeVerkehr\Assist;

final class ImageQualityAnalyzer
{
    public function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    public function analyze(string $path, string $mimeType): ?array
    {
        if (!$this->available() || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $image = $this->load($path, $mimeType);
        if (!$image instanceof \GdImage) {
            return null;
        }

        try {
            $sourceWidth = imagesx($image);
            $sourceHeight = imagesy($image);

            if ($sourceWidth < 1 || $sourceHeight < 1) {
                return null;
            }

            $sample = $this->sample($image, $sourceWidth, $sourceHeight);
            if (!$sample instanceof \GdImage) {
                return null;
            }

            try {
                $metrics = $this->metrics($sample);
            } finally {
                imagedestroy($sample);
            }

            $states = [
                'resolution' => $this->resolutionState($sourceWidth, $sourceHeight),
                'brightness' => $this->brightnessState($metrics['brightness_mean']),
                'contrast' => $this->contrastState($metrics['contrast_stddev']),
                'sharpness' => $this->sharpnessState($metrics['sharpness_score']),
            ];

            return [
                'width' => $sourceWidth,
                'height' => $sourceHeight,
                'brightness_mean' => round($metrics['brightness_mean'], 4),
                'contrast_stddev' => round($metrics['contrast_stddev'], 4),
                'sharpness_score' => round($metrics['sharpness_score'], 4),
                'resolution_state' => $states['resolution'],
                'brightness_state' => $states['brightness'],
                'contrast_state' => $states['contrast'],
                'sharpness_state' => $states['sharpness'],
                'overall_state' => $this->overallState($states),
                'warnings' => $this->warnings($states, $metrics),
                'algorithm' => 'local-gd-luminance-laplacian-v1',
                'sample_max_dimension' => 256,
            ];
        } finally {
            imagedestroy($image);
        }
    }

    private function sample(\GdImage $source, int $width, int $height): \GdImage|false
    {
        $scale = min(1, 256 / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$target instanceof \GdImage) {
            return false;
        }

        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        );

        return $target;
    }

    private function metrics(\GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $count = max(1, $width * $height);

        $luminance = [];
        $sum = 0.0;
        $sumSquares = 0.0;

        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $value = (0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b);
                $row[] = $value;
                $sum += $value;
                $sumSquares += $value * $value;
            }
            $luminance[] = $row;
        }

        $mean = $sum / $count;
        $variance = max(0.0, ($sumSquares / $count) - ($mean * $mean));
        $contrast = sqrt($variance);

        $laplacianSquares = 0.0;
        $laplacianCount = 0;

        if ($width >= 3 && $height >= 3) {
            for ($y = 1; $y < $height - 1; $y++) {
                for ($x = 1; $x < $width - 1; $x++) {
                    $center = $luminance[$y][$x];
                    $laplacian = (4 * $center)
                        - $luminance[$y][$x - 1]
                        - $luminance[$y][$x + 1]
                        - $luminance[$y - 1][$x]
                        - $luminance[$y + 1][$x];

                    $laplacianSquares += $laplacian * $laplacian;
                    $laplacianCount++;
                }
            }
        }

        $sharpness = $laplacianCount > 0
            ? sqrt($laplacianSquares / $laplacianCount)
            : 0.0;

        return [
            'brightness_mean' => $mean,
            'contrast_stddev' => $contrast,
            'sharpness_score' => $sharpness,
        ];
    }

    private function resolutionState(int $width, int $height): string
    {
        $long = max($width, $height);
        $short = min($width, $height);

        if ($long >= 1600 && $short >= 900) {
            return 'SUITABLE';
        }

        if ($long >= 1000 && $short >= 600) {
            return 'LIMITED';
        }

        return 'RETAKE_RECOMMENDED';
    }

    private function brightnessState(float $mean): string
    {
        if ($mean < 45 || $mean > 220) {
            return 'RETAKE_RECOMMENDED';
        }

        if ($mean < 65 || $mean > 200) {
            return 'LIMITED';
        }

        return 'SUITABLE';
    }

    private function contrastState(float $stddev): string
    {
        if ($stddev < 18) {
            return 'RETAKE_RECOMMENDED';
        }

        if ($stddev < 30) {
            return 'LIMITED';
        }

        return 'SUITABLE';
    }

    private function sharpnessState(float $score): string
    {
        if ($score < 8) {
            return 'RETAKE_RECOMMENDED';
        }

        if ($score < 16) {
            return 'LIMITED';
        }

        return 'SUITABLE';
    }

    private function overallState(array $states): string
    {
        if (in_array('RETAKE_RECOMMENDED', $states, true)) {
            return 'RETAKE_RECOMMENDED';
        }

        if (in_array('LIMITED', $states, true)) {
            return 'LIMITED';
        }

        return 'SUITABLE';
    }

    private function warnings(array $states, array $metrics): array
    {
        $warnings = [];

        if ($states['resolution'] !== 'SUITABLE') {
            $warnings[] = 'Auflösung ist für eine robuste Detailprüfung eingeschränkt.';
        }

        if ($states['brightness'] !== 'SUITABLE') {
            $warnings[] = $metrics['brightness_mean'] < 65
                ? 'Bild wirkt technisch zu dunkel.'
                : 'Bild wirkt technisch zu hell.';
        }

        if ($states['contrast'] !== 'SUITABLE') {
            $warnings[] = 'Bild weist geringen Helligkeitskontrast auf.';
        }

        if ($states['sharpness'] !== 'SUITABLE') {
            $warnings[] = 'Bild weist eine geringe lokale Schärfemetrik auf.';
        }

        return $warnings;
    }

    private function load(string $path, string $mimeType): \GdImage|false
    {
        return match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp')
                ? @imagecreatefromwebp($path)
                : false,
            default => false,
        };
    }
}
