<?php

declare(strict_types=1);

namespace MeldeVerkehr\Dispatch;

final class SmtpDispatchTransport implements DispatchTransportInterface
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $encryption,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly int $timeout = 15
    ) {
    }

    public function send(
        string $dispatchId,
        string $recipient,
        string $subject,
        string $text,
        array $attachments = [],
        array $headers = []
    ): array {
        if (
            $this->host === ''
            || $this->port < 1
            || !filter_var($recipient, FILTER_VALIDATE_EMAIL)
            || !filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL)
            || !$this->validHeaderValue($subject)
            || !$this->validHeaderValue($this->fromName)
        ) {
            return $this->failure('invalid_smtp_configuration');
        }

        $messageId = $headers['Message-ID'] ?? ('<mv-' . bin2hex(random_bytes(16)) . '@' . $this->messageDomain() . '>');
        $replyTo = $headers['Reply-To'] ?? null;
        $inReplyTo = $headers['In-Reply-To'] ?? null;

        if (
            !$this->validHeaderValue($messageId)
            || ($replyTo !== null && (!filter_var($replyTo, FILTER_VALIDATE_EMAIL) || !$this->validHeaderValue($replyTo)))
            || ($inReplyTo !== null && !$this->validHeaderValue($inReplyTo))
        ) {
            return $this->failure('invalid_reply_or_message_header');
        }

        $scheme = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($socket)) {
            return $this->failure('smtp_connect_failed', ['errno' => $errno]);
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO ' . $this->clientName(), [250]);

            if ($this->encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);

                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('SMTP TLS konnte nicht aktiviert werden.');
                }

                $this->command($socket, 'EHLO ' . $this->clientName(), [250]);
            }

            if ($this->username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($this->username), [334], false);
                $this->command($socket, base64_encode($this->password), [235], false);
            }

            $envelopeFrom = $replyTo !== null ? $replyTo : $this->fromAddress;
            $this->command($socket, 'MAIL FROM:<' . $envelopeFrom . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $raw = $this->buildMessage(
                $dispatchId,
                $recipient,
                $subject,
                $text,
                $attachments,
                $messageId,
                $replyTo,
                $inReplyTo
            );
            $raw = preg_replace('/(?m)^\./', '..', $raw) ?? $raw;
            fwrite($socket, $raw . "\r\n.\r\n");
            $response = $this->expect($socket, [250]);

            @fwrite($socket, "QUIT\r\n");

            return [
                'accepted' => true,
                'provider_reference' => 'smtp:' . hash('sha256', $response . $messageId),
                'response' => [
                    'mode' => 'smtp',
                    'reply_to' => $replyTo,
                    'in_reply_to' => $inReplyTo,
                    'server_response' => mb_substr($response, 0, 500),
                ],
                'message_id' => $messageId,
            ];
        } catch (\Throwable $e) {
            return $this->failure('smtp_error', [
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        } finally {
            fclose($socket);
        }
    }

    private function buildMessage(
        string $dispatchId,
        string $recipient,
        string $subject,
        string $text,
        array $attachments,
        string $messageId,
        ?string $replyTo,
        ?string $inReplyTo
    ): string {
        $boundary = 'mv_smtp_' . bin2hex(random_bytes(16));
        $encodedSubject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8')
            : $subject;
        $encodedFrom = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($this->fromName, 'UTF-8')
            : $this->fromName;

        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s +0000'),
            sprintf('From: %s <%s>', $encodedFrom, $this->fromAddress),
            'To: ' . $recipient,
            'Subject: ' . $encodedSubject,
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'X-MeldeVerkehr-Dispatch: ' . $dispatchId,
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        ];

        if ($replyTo !== null) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        if ($inReplyTo !== null) {
            $headers[] = 'In-Reply-To: ' . $inReplyTo;
            $headers[] = 'References: ' . $inReplyTo;
        }

        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $this->normalizeNewlines($text) . "\r\n";

        foreach ($attachments as $attachment) {
            $path = (string) ($attachment['path'] ?? '');
            $name = $this->safeFilename((string) ($attachment['name'] ?? 'anlage'));
            $mime = (string) ($attachment['mime_type'] ?? 'application/octet-stream');

            if (!is_file($path) || !is_readable($path)) {
                throw new \RuntimeException('SMTP-Anlage fehlt: ' . $name);
            }

            $data = file_get_contents($path);
            if ($data === false) {
                throw new \RuntimeException('SMTP-Anlage ist nicht lesbar: ' . $name);
            }

            $body .= '--' . $boundary . "\r\n"
                . 'Content-Type: ' . $mime . '; name="' . $name . '"' . "\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . 'Content-Disposition: attachment; filename="' . $name . '"' . "\r\n\r\n"
                . chunk_split(base64_encode($data), 76, "\r\n");
        }

        $body .= '--' . $boundary . "--\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function command($socket, string $command, array $codes, bool $loggable = true): string
    {
        if (fwrite($socket, $command . "\r\n") === false) {
            throw new \RuntimeException('SMTP-Befehl konnte nicht geschrieben werden.');
        }

        return $this->expect($socket, $codes);
    }

    private function expect($socket, array $codes): string
    {
        $lines = [];

        while (($line = fgets($socket, 4096)) !== false) {
            $lines[] = rtrim($line, "\r\n");

            if (strlen($line) >= 4 && $line[3] !== '-') {
                break;
            }
        }

        if ($lines === []) {
            throw new \RuntimeException('Keine SMTP-Antwort erhalten.');
        }

        $last = end($lines);
        $code = (int) substr((string) $last, 0, 3);

        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException('Unerwartete SMTP-Antwort: ' . mb_substr(implode(' | ', $lines), 0, 800));
        }

        return implode("\n", $lines);
    }

    private function failure(string $reason, array $extra = []): array
    {
        return [
            'accepted' => false,
            'provider_reference' => null,
            'response' => array_merge(['reason' => $reason], $extra),
            'message_id' => null,
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

    private function normalizeNewlines(string $text): string
    {
        return preg_replace("/\r\n|\r|\n/", "\r\n", $text) ?? $text;
    }

    private function messageDomain(): string
    {
        $parts = explode('@', $this->fromAddress, 2);

        return $parts[1] ?? 'localhost';
    }

    private function clientName(): string
    {
        return preg_replace('/[^A-Za-z0-9.-]/', '', gethostname() ?: 'meldverkehr') ?: 'meldverkehr';
    }
}
