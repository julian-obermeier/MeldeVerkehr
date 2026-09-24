<?php

declare(strict_types=1);

namespace MeldeVerkehr\Support;

final class ViewStyleCompiler
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function css(string $template): string
    {
        $source = $this->source($template);
        $chunks = [];

        if (preg_match_all('~<style\\b[^>]*>(.*?)</style>~is', $source, $matches)) {
            foreach ($matches[1] as $css) {
                $css = trim((string) $css);
                if ($css !== '') {
                    $chunks[] = $css;
                }
            }
        }

        if (preg_match_all('~\\sstyle\\s*=\\s*(["\'])(.*?)\\1~is', $source, $matches, PREG_SET_ORDER)) {
            $seen = [];
            foreach ($matches as $match) {
                $style = trim((string) $match[2]);
                if ($style === '' || str_contains($style, '<?')) {
                    continue;
                }

                $hash = $this->styleHash($style);
                if (isset($seen[$hash])) {
                    continue;
                }

                $seen[$hash] = true;
                $chunks[] = '[data-mv-style="' . $hash . '"]{' . $style . '}';
            }
        }

        return trim(implode("\n", $chunks));
    }

    public function externalize(string $html, string $template): string
    {
        $css = $this->css($template);

        $html = (string) preg_replace(
            '~<style\\b[^>]*>.*?</style>~is',
            '',
            $html
        );

        $html = (string) preg_replace_callback(
            '~\\sstyle\\s*=\\s*(["\'])(.*?)\\1~is',
            function (array $match): string {
                $style = trim((string) $match[2]);

                return $style === ''
                    ? ''
                    : ' data-mv-style="' . $this->styleHash($style) . '"';
            },
            $html
        );

        $links = '<link rel="stylesheet" href="/assets/app.css">';
        if ($css !== '') {
            $links .= '<link rel="stylesheet" href="/assets/view-style.css?view='
                . rawurlencode($template)
                . '&v=' . substr(hash('sha256', $css), 0, 16)
                . '">';
        }

        if (str_contains($html, '</head>')) {
            $html = preg_replace(
                '~</head>~i',
                $links . '</head>',
                $html,
                1
            ) ?? $html;
        }

        return $html;
    }

    private function source(string $template): string
    {
        if (
            $template === ''
            || !preg_match('~^[A-Za-z0-9_/-]+$~', $template)
            || str_contains($template, '..')
        ) {
            throw new \InvalidArgumentException('Ungültige View-Kennung.');
        }

        $path = rtrim($this->basePath, '/\\')
            . '/'
            . ltrim($template, '/')
            . '.php';

        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('View-Stylesheet nicht gefunden.');
        }

        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException('View-Stylesheet konnte nicht gelesen werden.');
        }

        return $source;
    }

    private function styleHash(string $style): string
    {
        return substr(hash('sha256', trim($style)), 0, 16);
    }
}
