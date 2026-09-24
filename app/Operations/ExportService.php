<?php

declare(strict_types=1);

namespace MeldeVerkehr\Operations;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Support\Uuid;
use PDO;

final class ExportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CaseSearchService $search,
        private readonly ExportStorage $storage,
        private readonly AuditLogger $audit
    ) {
    }

    public function createCaseExport(
        string $userId,
        string $format,
        bool $includeSensitive,
        array $filters = []
    ): array {
        $format = strtolower(trim($format));
        if (!in_array($format, ['csv','json'], true)) {
            throw new \InvalidArgumentException('Exportformat muss CSV oder JSON sein.');
        }

        $result = $this->search->search($userId, $filters, 250);
        $rows = $result['results'];

        if (!$includeSensitive) {
            foreach ($rows as &$row) {
                unset($row['license_plate']);
            }
            unset($row);
        }

        if ($format === 'json') {
            $content = json_encode(
                [
                    'schema_version' => 1,
                    'generated_at' => gmdate('c'),
                    'includes_sensitive' => $includeSensitive,
                    'filters' => $result['filters'],
                    'count' => count($rows),
                    'cases' => $rows,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            $mime = 'application/json';
        } else {
            $content = $this->csv($rows, $includeSensitive);
            $mime = 'text/csv; charset=UTF-8';
        }

        $stored = $this->storage->store($userId, $format, $content);
        $id = Uuid::v4();
        $filename = sprintf(
            'meldeverkehr-faelle-%s.%s',
            gmdate('Ymd-His'),
            $format
        );

        $this->pdo->prepare(
            'INSERT INTO export_artifacts
             (id, user_id, export_type, format, filename, storage_path, mime_type,
              file_size, sha256, includes_sensitive, status, created_at, expires_at)
             VALUES
             (:id, :user_id, "CASES", :format, :filename, :storage_path, :mime_type,
              :file_size, :sha256, :includes_sensitive, "READY", UTC_TIMESTAMP(),
              DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY))'
        )->execute([
            'id' => $id,
            'user_id' => $userId,
            'format' => $format,
            'filename' => $filename,
            'storage_path' => $stored['relative_path'],
            'mime_type' => $mime,
            'file_size' => $stored['size'],
            'sha256' => $stored['sha256'],
            'includes_sensitive' => $includeSensitive ? 1 : 0,
        ]);

        $this->audit->log('USER_EXPORT_CREATED', 'export_artifact', $id, 'USER', $userId, [
            'format' => $format,
            'includes_sensitive' => $includeSensitive,
            'count' => count($rows),
        ]);

        return [
            'id' => $id,
            'filename' => $filename,
            'format' => $format,
            'mime_type' => $mime,
            'file_size' => $stored['size'],
            'sha256' => $stored['sha256'],
            'includes_sensitive' => $includeSensitive,
            'count' => count($rows),
        ];
    }

    public function binary(string $userId, string $exportId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM export_artifacts
             WHERE id = :id AND user_id = :user_id AND status = "READY" LIMIT 1'
        );
        $stmt->execute(['id' => $exportId, 'user_id' => $userId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new \DomainException('Export nicht gefunden.');
        }

        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
            throw new \DomainException('Export ist abgelaufen.');
        }

        $content = $this->storage->readVerified(
            (string) $row['storage_path'],
            (string) $row['sha256']
        );

        return [
            'body' => $content,
            'filename' => (string) $row['filename'],
            'mime_type' => (string) $row['mime_type'],
            'sha256' => (string) $row['sha256'],
        ];
    }

    public function cleanupExpired(): int
    {
        $stmt = $this->pdo->query(
            'SELECT id, storage_path FROM export_artifacts
             WHERE status = "READY"
               AND expires_at IS NOT NULL
               AND expires_at < UTC_TIMESTAMP()'
        );

        $count = 0;
        foreach ($stmt->fetchAll() as $row) {
            $this->storage->delete((string) $row['storage_path']);
            $this->pdo->prepare(
                'UPDATE export_artifacts SET status = "EXPIRED" WHERE id = :id'
            )->execute(['id' => $row['id']]);
            $count++;
        }

        return $count;
    }

    private function csv(array $rows, bool $includeSensitive): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('CSV-Export konnte nicht initialisiert werden.');
        }

        $headers = [
            'public_number','status','observed_from','street','house_number',
            'postal_code','city','vehicle_type','offense_title','offense_code',
            'offense_category','authority_name','updated_at'
        ];
        if ($includeSensitive) {
            $headers[] = 'license_plate';
        }

        fputcsv($handle, $headers, ';');

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $row[$header] ?? '';
            }
            fputcsv($handle, $line, ';');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        if ($content === false) {
            throw new \RuntimeException('CSV-Export konnte nicht erzeugt werden.');
        }

        return "\xEF\xBB\xBF" . $content;
    }
}
