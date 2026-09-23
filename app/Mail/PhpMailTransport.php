<?php

declare(strict_types=1);

namespace MeldeVerkehr\Mail;

final class PhpMailTransport implements MailTransportInterface
{
    public function __construct(
        private readonly string $fromAddress,
        private readonly string $fromName
    ) {
    }

    public function send(string $to, string $subject, string $html, string $text = ''): bool
    {
        if (!function_exists('mail') || !$this->validHeaderValue($to) || !$this->validHeaderValue($subject)) {
            return false;
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $boundary = 'mv_' . bin2hex(random_bytes(12));
        $headers = [
            'MIME-Version: 1.0',
            sprintf('From: %s <%s>', $this->safeDisplayName($this->fromName), $this->fromAddress),
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $plain = $text !== '' ? $text : strip_tags($html);
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $plain . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $html . "\r\n"
            . '--' . $boundary . "--\r\n";

        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }

    private function validHeaderValue(string $value): bool
    {
        return !str_contains($value, "\r") && !str_contains($value, "\n");
    }

    private function safeDisplayName(string $value): string
    {
        return str_replace(['\r', '\n', '"'], '', $value);
    }
}
