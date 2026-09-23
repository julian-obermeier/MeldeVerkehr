<?php

declare(strict_types=1);

namespace MeldeVerkehr\Support;

final class View
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function render(string $template, array $data = []): string
    {
        $path = rtrim($this->basePath, '/') . '/' . ltrim($template, '/') . '.php';

        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('View %s not found.', $template));
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $path;

        return (string) ob_get_clean();
    }
}
