<?php

declare(strict_types=1);

namespace MeldeVerkehr\Support;

final class AccessibilityHtmlEnhancer
{
    public function enhance(string $html): string
    {
        if (!str_contains($html, '<html') || !str_contains($html, '<body')) {
            return $html;
        }

        $hasMain = preg_match('/<main\b/i', $html) === 1;
        if (!$hasMain) {
            return $html;
        }

        if (preg_match('/<main\b[^>]*\bid=["\']main-content["\']/i', $html) !== 1) {
            $html = preg_replace(
                '/<main\b([^>]*)>/i',
                '<main id="main-content" tabindex="-1"$1>',
                $html,
                1
            ) ?? $html;
        } elseif (preg_match('/<main\b[^>]*\btabindex=/i', $html) !== 1) {
            $html = preg_replace(
                '/<main\b([^>]*)>/i',
                '<main$1 tabindex="-1">',
                $html,
                1
            ) ?? $html;
        }

        if (!str_contains($html, 'class="mv-skip-link"')) {
            $html = preg_replace(
                '/<body([^>]*)>/i',
                '<body$1><a class="mv-skip-link" href="#main-content">Zum Inhalt springen</a>',
                $html,
                1
            ) ?? $html;
        }

        if (!str_contains($html, 'data-mv-accessibility="1"')) {
            $style = '<style data-mv-accessibility="1">'
                . '.mv-skip-link{position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden}'
                . '.mv-skip-link:focus{left:12px;top:12px;width:auto;height:auto;overflow:visible;z-index:10000;padding:10px 14px;background:#fff;color:#111;border:2px solid currentColor;border-radius:6px}'
                . ':focus-visible{outline:3px solid currentColor;outline-offset:3px}'
                . '@media (prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important;animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}}'
                . '</style>';

            $html = str_ireplace('</head>', $style . '</head>', $html);
        }

        return $html;
    }
}
