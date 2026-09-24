<?php

declare(strict_types=1);

namespace MeldeVerkehr\Communication;

final class ImapMailSource implements InboundMailSourceInterface
{
    private mixed $stream = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username,
        private readonly string $password,
        private readonly string $folder = 'INBOX'
    ) {
    }

    public function fetch(int $limit = 20): array
    {
        $stream = $this->open();
        $uids = imap_search($stream, 'UNSEEN', SE_UID);

        if ($uids === false) {
            return [];
        }

        sort($uids, SORT_NUMERIC);
        $uids = array_slice($uids, 0, max(1, $limit));
        $messages = [];

        foreach ($uids as $uid) {
            $messages[] = $this->readMessage((int) $uid);
        }

        return $messages;
    }

    public function acknowledge(string $sourceId): void
    {
        if (!ctype_digit($sourceId)) {
            throw new \InvalidArgumentException('Ungültige IMAP-UID.');
        }

        $stream = $this->open();

        if (!imap_setflag_full($stream, $sourceId, '\\Seen', ST_UID)) {
            throw new \RuntimeException('IMAP-Nachricht konnte nicht als verarbeitet markiert werden.');
        }
    }

    public function __destruct()
    {
        if ($this->stream !== null && function_exists('imap_close')) {
            @imap_close($this->stream);
        }
    }

    private function open(): mixed
    {
        if ($this->stream !== null) {
            return $this->stream;
        }

        if (!function_exists('imap_open')) {
            throw new \RuntimeException('PHP-IMAP-Erweiterung ist nicht verfügbar.');
        }

        if ($this->host === '' || $this->username === '' || $this->password === '') {
            throw new \RuntimeException('IMAP-Zugang ist nicht vollständig konfiguriert.');
        }

        $suffix = match (strtolower($this->encryption)) {
            'ssl' => '/imap/ssl',
            'tls' => '/imap/tls',
            'none', '' => '/imap/notls',
            default => throw new \RuntimeException('Unbekannte IMAP-Verschlüsselung.'),
        };
        $mailbox = sprintf(
            '{%s:%d%s}%s',
            $this->host,
            $this->port,
            $suffix,
            $this->folder
        );

        $stream = @imap_open($mailbox, $this->username, $this->password);

        if ($stream === false) {
            throw new \RuntimeException('IMAP-Verbindung fehlgeschlagen.');
        }

        $this->stream = $stream;

        return $this->stream;
    }

    private function readMessage(int $uid): array
    {
        $stream = $this->open();
        $overviewRows = imap_fetch_overview($stream, (string) $uid, FT_UID);
        $overview = is_array($overviewRows) && isset($overviewRows[0]) ? $overviewRows[0] : null;
        $msgNo = imap_msgno($stream, $uid);

        if ($msgNo <= 0) {
            throw new \RuntimeException('IMAP-Nachricht konnte nicht aufgelöst werden.');
        }

        $header = imap_headerinfo($stream, $msgNo);
        $rawHeader = imap_fetchheader($stream, $uid, FT_UID);
        $structure = imap_fetchstructure($stream, (string) $uid, FT_UID);

        $plainParts = [];
        $htmlParts = [];
        $attachments = [];

        if ($structure !== false) {
            if (!empty($structure->parts) && is_array($structure->parts)) {
                foreach ($structure->parts as $index => $part) {
                    $this->walkPart(
                        $uid,
                        $part,
                        (string) ($index + 1),
                        $plainParts,
                        $htmlParts,
                        $attachments
                    );
                }
            } else {
                $raw = imap_body($stream, $uid, FT_UID | FT_PEEK);
                $decoded = $this->decodeBody((string) $raw, (int) ($structure->encoding ?? 0));
                $mime = $this->mimeType($structure);
                if ($mime === 'text/html') {
                    $htmlParts[] = $decoded;
                } else {
                    $plainParts[] = $decoded;
                }
            }
        }

        $text = trim(implode("\n\n", array_filter($plainParts, static fn(string $v): bool => trim($v) !== '')));
        if ($text === '' && $htmlParts !== []) {
            $text = trim(html_entity_decode(
                strip_tags(implode("\n\n", $htmlParts)),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ));
        }

        $to = $this->addresses(is_object($header) ? ($header->to ?? []) : []);
        $from = $this->addresses(is_object($header) ? ($header->from ?? []) : []);
        $messageId = is_object($overview) && isset($overview->message_id)
            ? trim((string) $overview->message_id)
            : null;
        $inReplyTo = is_object($overview) && isset($overview->in_reply_to)
            ? trim((string) $overview->in_reply_to)
            : $this->headerValue((string) $rawHeader, 'In-Reply-To');

        $receivedAt = null;
        $date = is_object($overview) && isset($overview->date) ? (string) $overview->date : '';
        if ($date !== '') {
            try {
                $receivedAt = (new \DateTimeImmutable($date))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                $receivedAt = null;
            }
        }

        return [
            'source_id' => (string) $uid,
            'message_id' => $messageId === '' ? null : $messageId,
            'in_reply_to' => $inReplyTo === '' ? null : $inReplyTo,
            'from' => $from[0] ?? '',
            'to' => $to,
            'subject' => $this->decodeHeader(
                is_object($overview) && isset($overview->subject) ? (string) $overview->subject : ''
            ),
            'text' => $text,
            'received_at' => $receivedAt,
            'attachments' => $attachments,
        ];
    }

    private function walkPart(
        int $uid,
        object $part,
        string $section,
        array &$plainParts,
        array &$htmlParts,
        array &$attachments
    ): void {
        if (!empty($part->parts) && is_array($part->parts)) {
            foreach ($part->parts as $index => $child) {
                $this->walkPart(
                    $uid,
                    $child,
                    $section . '.' . ($index + 1),
                    $plainParts,
                    $htmlParts,
                    $attachments
                );
            }
            return;
        }

        $raw = imap_fetchbody($this->open(), $uid, $section, FT_UID | FT_PEEK);
        $decoded = $this->decodeBody((string) $raw, (int) ($part->encoding ?? 0));
        $mime = $this->mimeType($part);
        $filename = $this->filename($part);
        $disposition = strtoupper((string) ($part->disposition ?? ''));

        if ($filename !== '' || $disposition === 'ATTACHMENT') {
            $attachments[] = [
                'filename' => $filename !== '' ? $filename : 'anlage',
                'mime_type' => $mime,
                'content' => $decoded,
            ];
            return;
        }

        if ($mime === 'text/plain') {
            $plainParts[] = $this->toUtf8($decoded, $part);
        } elseif ($mime === 'text/html') {
            $htmlParts[] = $this->toUtf8($decoded, $part);
        }
    }

    private function decodeBody(string $raw, int $encoding): string
    {
        return match ($encoding) {
            3 => base64_decode($raw, true) ?: '',
            4 => quoted_printable_decode($raw),
            default => $raw,
        };
    }

    private function mimeType(object $part): string
    {
        $types = [
            0 => 'text',
            1 => 'multipart',
            2 => 'message',
            3 => 'application',
            4 => 'audio',
            5 => 'image',
            6 => 'video',
            7 => 'other',
        ];
        $primary = $types[(int) ($part->type ?? 7)] ?? 'application';
        $subtype = strtolower((string) ($part->subtype ?? 'octet-stream'));

        return $primary . '/' . $subtype;
    }

    private function filename(object $part): string
    {
        foreach (['dparameters', 'parameters'] as $field) {
            $params = $part->{$field} ?? [];
            if (!is_array($params)) {
                continue;
            }
            foreach ($params as $param) {
                $attribute = strtolower((string) ($param->attribute ?? ''));
                if (in_array($attribute, ['filename', 'name'], true)) {
                    return $this->decodeHeader((string) ($param->value ?? ''));
                }
            }
        }

        return '';
    }

    private function toUtf8(string $text, object $part): string
    {
        $charset = null;
        foreach (($part->parameters ?? []) as $param) {
            if (strtolower((string) ($param->attribute ?? '')) === 'charset') {
                $charset = strtoupper((string) ($param->value ?? ''));
                break;
            }
        }

        if ($charset !== null && $charset !== '' && $charset !== 'UTF-8' && function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($text, 'UTF-8', $charset);
        }

        return $text;
    }

    private function addresses(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $addresses = [];
        foreach ($items as $item) {
            $mailbox = trim((string) ($item->mailbox ?? ''));
            $host = trim((string) ($item->host ?? ''));
            if ($mailbox !== '' && $host !== '') {
                $addresses[] = strtolower($mailbox . '@' . $host);
            }
        }

        return array_values(array_unique($addresses));
    }

    private function decodeHeader(string $value): string
    {
        if ($value === '' || !function_exists('imap_mime_header_decode')) {
            return $value;
        }

        $parts = imap_mime_header_decode($value);
        $decoded = '';

        foreach ($parts as $part) {
            $charset = strtoupper((string) ($part->charset ?? 'UTF-8'));
            $text = (string) ($part->text ?? '');
            if ($charset !== 'DEFAULT' && $charset !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                $text = mb_convert_encoding($text, 'UTF-8', $charset);
            }
            $decoded .= $text;
        }

        return $decoded;
    }

    private function headerValue(string $header, string $name): ?string
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+(?:\r?\n[ \t].+)*)$/mi', $header, $m)) {
            return trim(preg_replace('/\r?\n[ \t]+/', ' ', $m[1]) ?? $m[1]);
        }

        return null;
    }
}
