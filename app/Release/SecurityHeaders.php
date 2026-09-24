<?php

declare(strict_types=1);

namespace MeldeVerkehr\Release;

final class SecurityHeaders
{
    public static function send(bool $https): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: geolocation=(self), camera=(self), microphone=()');
        header(
            "Content-Security-Policy: default-src 'self'; " .
            "base-uri 'self'; object-src 'none'; frame-ancestors 'none'; " .
            "form-action 'self'; script-src 'self' 'unsafe-inline'; " .
            "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; " .
            "connect-src 'self'; font-src 'self'; manifest-src 'self'; " .
            "worker-src 'self'; media-src 'self' blob:"
        );
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');

        if ($https) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
