<?php

declare(strict_types=1);

namespace MeldeVerkehr\Support;

final class View
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function render(string $template, array $viewData = []): string
    {
        $path = rtrim($this->basePath, '/') . '/' . ltrim($template, '/') . '.php';

        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('View %s not found.', $template));
        }

        extract($viewData, EXTR_SKIP);

        ob_start();
        require $path;

        $html = (string) ob_get_clean();

        return (new AccessibilityHtmlEnhancer())->enhance($html);
    }
}
