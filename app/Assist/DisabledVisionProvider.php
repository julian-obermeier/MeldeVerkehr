<?php

declare(strict_types=1);

namespace MeldeVerkehr\Assist;

final class DisabledVisionProvider implements VisionProviderInterface
{
    public function name(): string
    {
        return 'disabled';
    }

    public function enabled(): bool
    {
        return false;
    }

    public function analyze(
        string $purpose,
        string $imagePath,
        string $mimeType,
        array $context = []
    ): array {
        throw new \DomainException(
            'Externe Vision-/OCR-Analyse ist deaktiviert. Es werden keine Bilder an externe Dienste übertragen.'
        );
    }
}
