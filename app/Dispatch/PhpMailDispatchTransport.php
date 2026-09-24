<?php

declare(strict_types=1);

namespace MeldeVerkehr\Dispatch;

final class PhpMailDispatchTransport implements DispatchTransportInterface
{
    public function __construct(
        private readonly string $fromAddress,
        private readonly string $fromName
    ) {
    }

    public function send(
        string $dispatchId,
        string $recipient,
        string $subject,
        string $text,
        array $attachments = []
    ): array {
        if (
            !function_exists('mail')
            || !filter_var($recipient, FILTER_VALIDATE_EMAIL)
            || !filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL)
            || !$this->validHeaderValue($subject)
            || !$this->validHeaderValue($this->fromName)
        ) {
            return [
                'accepted' => false,
                'provider_reference' => null,
                'response' => ['reason' => 'mail_transport_not_available_or_invalid_headers'],
            ];
        }

        $boundary = 'mv_dispatch_' . bin2hex(random_bytes(16));
        $encodedSubject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8')
            : $subject;
        $encodedFrom = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($this->fromName, 'UTF-8')
            : $this->fromName;

        $headers = [
            'MIME-Version: 1.0',
            sprintf('From: %s <%s>', $encodedFrom, $this->fromAddress),
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            'X-MeldeVerkehr-Dispatch: ' . $dispatchId,
        ];

        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $text . "\r\n";

        foreach ($attachments as $attachment) {
            $path = (string) ($attachment['path'] ?? '');
            $name = $this->safeFilename((string) ($attachment['name'] ?? 'anlage'));
            $mime = (string) ($attachment['mime_type'] ?? 'application/octet-stream');

            if (!is_file($path) || !is_readable($path)) {
                return [
                    'accepted' => false,
                    'provider_reference' => null,
                    'response' => ['reason' => 'attachment_missing', 'name' => $name],
                ];
            }

            $data = file_get_contents($path);
            if ($data === false) {
                return [
                    'accepted' => false,
                    'provider_reference' => null,
                    'response' => ['reason' => 'attachment_unreadable', 'name' => $name],
                ];
            }

            $body .= '--' . $boundary . "\r\n"
                . 'Content-Type: ' . $mime . '; name="' . $name . '"' . "\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . 'Content-Disposition: attachment; filename="' . $name . '"' . "\r\n\r\n"
                . chunk_split(base64_encode($data), 76, "\r\n");
        }

        $body .= '--' . $boundary . "--\r\n";
        $accepted = @mail($recipient, $encodedSubject, $body, implode("\r\n", $headers));

        return [
            'accepted' => $accepted,
            'provider_reference' => $accepted ? 'php-mail:' . $dispatchId : null,
            'response' => ['mode' => 'php_mail'],
        ];
    }

    private function validHeaderValue(string $value): bool
    {
        return !str_contains($value, "\r") && !str_contains($value, "\n");
    }

    private function safeFilename(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($value)) ?? 'anlage';

        return substr($value, 0, 180);
    }
}
