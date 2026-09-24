<?php

declare(strict_types=1);

namespace MeldeVerkehr\AuthorityPortal;

final class AuthorityExportService
{
    public function __construct(
        private readonly AuthorityPortalService $portal,
        private readonly AuthorityAccessService $access
    ) {
    }

    public function export(
        string $userId,
        string $authorityId,
        string $format,
        array $filters = []
    ): array {
        $this->access->assertAuthority(
            $userId,
            $authorityId,
            'authority.case.export'
        );

        $format = strtolower(trim($format));
        if (!in_array($format, ['csv','json','xml'], true)) {
            throw new \InvalidArgumentException('Exportformat muss CSV, JSON oder XML sein.');
        }

        $rows = $this->portal->inbox(
            $userId,
            $authorityId,
            $filters['status'] ?? null,
            $filters['q'] ?? null,
            250
        );

        $normalized = array_map(static fn(array $row): array => [
            'public_number' => (string) $row['public_number'],
            'status' => (string) $row['status'],
            'observed_from' => $row['observed_from'],
            'street' => $row['street'],
            'postal_code' => $row['postal_code'],
            'city' => $row['city'],
            'authority_name' => (string) $row['authority_name'],
            'sent_at' => $row['sent_at'],
            'open_inquiries' => (int) $row['open_inquiries'],
        ], $rows);

        return match ($format) {
            'json' => [
                'body' => json_encode(
                    [
                        'schema_version' => 1,
                        'generated_at' => gmdate('c'),
                        'count' => count($normalized),
                        'cases' => $normalized,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ) . PHP_EOL,
                'mime_type' => 'application/json',
                'extension' => 'json',
            ],
            'xml' => [
                'body' => $this->xml($normalized),
                'mime_type' => 'application/xml; charset=UTF-8',
                'extension' => 'xml',
            ],
            default => [
                'body' => $this->csv($normalized),
                'mime_type' => 'text/csv; charset=UTF-8',
                'extension' => 'csv',
            ],
        };
    }

    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('CSV-Export konnte nicht initialisiert werden.');
        }

        $headers = [
            'public_number','status','observed_from','street','postal_code',
            'city','authority_name','sent_at','open_inquiries'
        ];
        fputcsv($handle, $headers, ';');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                static fn(string $header): mixed => $row[$header] ?? '',
                $headers
            ), ';');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        if ($content === false) {
            throw new \RuntimeException('CSV-Export konnte nicht erzeugt werden.');
        }

        return "\xEF\xBB\xBF" . $content;
    }

    private function xml(array $rows): string
    {
        $escape = static fn(mixed $value): string => htmlspecialchars(
            (string) ($value ?? ''),
            ENT_XML1 | ENT_QUOTES,
            'UTF-8'
        );

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<meldeverkehr-export schema-version="1" generated-at="' . $escape(gmdate('c')) . '">' . "\n";

        foreach ($rows as $row) {
            $xml .= "  <case>\n";
            foreach ($row as $key => $value) {
                $tag = preg_replace('/[^a-z0-9_-]/i', '', (string) $key) ?: 'field';
                $xml .= '    <' . $tag . '>' . $escape($value) . '</' . $tag . ">'\n";
            }
            $xml .= "  </case>\n";
        }

        $xml .= "</meldeverkehr-export>\n";

        return $xml;
    }
}
