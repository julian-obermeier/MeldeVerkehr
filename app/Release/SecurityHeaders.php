<?php

declare(strict_types=1);

namespace MeldeVerkehr\Release;

final class SecurityHeaders
{
    public static function policy(): string
    {
        return "default-src 'self'; "
            . "base-uri 'self'; object-src 'none'; frame-ancestors 'none'; frame-src 'none'; "
            . "form-action 'self'; script-src 'self'; script-src-attr 'none'; "
            . "style-src 'self'; style-src-attr 'none'; img-src 'self' data: blob:; "
            . "connect-src 'self'; font-src 'self'; manifest-src 'self'; "
            . "worker-src 'self'; media-src 'self' blob:";
    }

    public static function send(bool $https): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: geolocation=(self), camera=(self), microphone=()');
        header('Content-Security-Policy: ' . self::policy());
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');

        if ($https) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
