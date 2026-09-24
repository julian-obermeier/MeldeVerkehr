<?php

declare(strict_types=1);

namespace MeldeVerkehr\Http;

final class Response
{
    public function __construct(
        private readonly string $body,
        private readonly int $status = 200,
        private readonly array $headers = []
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public static function binary(
        string $body,
        string $contentType,
        int $status = 200,
        array $headers = []
    ): self {
        return new self(
            $body,
            $status,
            array_merge([
                'Content-Type' => $contentType,
                'Content-Length' => (string) strlen($body),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
            ], $headers)
        );
    }

    public static function redirect(string $location, int $status = 302): self
    {
        if (!str_starts_with($location, '/') && !preg_match('#^https?://#i', $location)) {
            throw new \InvalidArgumentException('Redirect location must be absolute or root-relative.');
        }

        return new self('', $status, ['Location' => $location]);
    }

    public function send(): void
    {
        http_response_code($this->status);

        $headers = array_merge(
            $this->securityHeaders(),
            $this->headers
        );

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
    }

    private function securityHeaders(): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'geolocation=(self), camera=(self), microphone=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self'; worker-src 'self' blob:",
            'Cache-Control' => 'no-store, max-age=0',
        ];

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        if ($https) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        return $headers;
    }
}
