<?php

declare(strict_types=1);

namespace MeldeVerkehr\Assist;

interface VisionProviderInterface
{
    public function name(): string;

    public function enabled(): bool;

    /**
     * Provider responses are untrusted suggestions.
     *
     * @return array{
     *   suggestions: array<int,array{type:string,value:array,confidence?:float|null}>,
     *   metadata?: array<string,mixed>
     * }
     */
    public function analyze(
        string $purpose,
        string $imagePath,
        string $mimeType,
        array $context = []
    ): array;
}
