<?php

declare(strict_types=1);

namespace MeldeVerkehr\Operations;

use PDO;

final class DocumentCenterService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(string $userId, ?string $type = null, ?string $query = null): array
    {
        $items = array_merge(
            $this->evidencePackages($userId),
            $this->witnessReports($userId),
            $this->dispatchPackages($userId),
            $this->authorityAttachments($userId),
            $this->closureDossiers($userId),
            $this->municipalReports($userId),
            $this->exports($userId)
        );

        $type = strtoupper(trim((string) ($type ?? '')));
        if ($type !== '') {
            $items = array_values(array_filter(
                $items,
                static fn(array $item): bool => $item['type'] === $type
            ));
        }

        $query = mb_strtolower(trim((string) ($query ?? '')), 'UTF-8');
        if ($query !== '') {
            $items = array_values(array_filter(
                $items,
                static function (array $item) use ($query): bool {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        $item['title'] ?? null,
                        $item['public_number'] ?? null,
                        $item['type'] ?? null,
                        $item['filename'] ?? null,
                    ])), 'UTF-8');

                    return str_contains($haystack, $query);
                }
            ));
        }

        usort(
            $items,
            static fn(array $a, array $b): int =>
                strcmp((string) $b['created_at'], (string) $a['created_at'])
        );

        return $items;
    }

    private function evidencePackages(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ep.id, ep.case_id, ep.version_no, ep.manifest_sha256, ep.frozen_at,
                    c.public_number
             FROM evidence_packages ep
             INNER JOIN cases c ON c.id = ep.case_id
             WHERE c.user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        return array_map(static fn(array $row): array => [
            'type' => 'EVIDENCE_PACKAGE',
            'id' => $row['id'],
            'case_id' => $row['case_id'],
            'public_number' => $row['public_number'],
            'title' => 'Beweismappe ' . $row['public_number'],
            'filename' => null,
            'version' => (int) $row['version_no'],
            'mime_type' => null,
            'file_size' => null,
            'sha256' => $row['manifest_sha256'],
            'created_at' => $row['frozen_at'],
            'open_url' => '/cases/' . rawurlencode((string) $row['case_id']) . '/evidence/review',
            'download_url' => null,
        ], $stmt->fetchAll());
    }

    private function witnessReports(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT wr.id, wr.case_id, wr.version_no, wr.snapshot_sha256, wr.created_at,
                    c.public_number
             FROM witness_reports wr
             INNER JOIN cases c ON c.id = wr.case_id
             WHERE c.user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        return array_map(static fn(array $row): array => [
            'type' => 'WITNESS_REPORT',
            'id' => $row['id'],
            'case_id' => $row['case_id'],
            'public_number' => $row['public_number'],
            'title' => 'Zeugenbericht ' . $row['public_number'],
            'filename' => null,
            'version' => (int) $row['version_no'],
            'mime_type' => 'application/json',
            'file_size' => null,
            'sha256' => $row['snapshot_sha256'],
            'created_at' => $row['created_at'],
            'open_url' => '/cases/' . rawurlencode((string) $row['case_id']) . '/witness',
            'download_url' => null,
        ], $stmt->fetchAll());
    }

    private function dispatchPackages(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT dp.id, dp.case_id, dp.version_no, dp.manifest_sha256, dp.created_at,
                    c.public_number, a.name AS authority_name
             FROM dispatch_packages dp
             INNER JOIN cases c ON c.id = dp.case_id
             INNER JOIN authorities a ON a.id = dp.authority_id
             WHERE c.user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        return array_map(static fn(array $row): array => [
            'type' => 'DISPATCH_PACKAGE',
            'id' => $row['id'],
            'case_id' => $row['case_id'],
            'public_number' => $row['public_number'],
            'title' => 'Versandpaket ' . $row['public_number'] . ' – ' . $row['authority_name'],
            'filename' => null,
            'version' => (int) $row['version_no'],
            'mime_type' => null,
            'file_size' => null,
            'sha256' => $row['manifest_sha256'],
            'created_at' => $row['created_at'],
            'open_url' => '/cases/' . rawurlencode((string) $row['case_id']) . '/dispatch',
            'download_url' => null,
        ], $stmt->fetchAll());
    }

    private function authorityAttachments(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.original_filename, a.mime_type, a.file_size, a.sha256, a.created_at,
                    m.case_id, c.public_number
             FROM authority_message_attachments a
             INNER JOIN authority_messages m ON m.id = a.message_id
             INNER JOIN cases c ON c.id = m.case_id
             WHERE c.user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        return array_map(static fn(array $row): array => [
            'type' => 'AUTHORITY_ATTACHMENT',
            'id' => $row['id'],
            'case_id' => $row['case_id'],
            'public_number' => $row['public_number'],
            'title' => 'Behördenanhang – ' . $row['public_number'],
            'filename' => $row['original_filename'],
            'version' => null,
            'mime_type' => $row['mime_type'],
            'file_size' => (int) $row['file_size'],
            'sha256' => $row['sha256'],
            'created_at' => $row['created_at'],
            'open_url' => '/cases/' . rawurlencode((string) $row['case_id']) . '/communication',
            'download_url' => '/communication-attachments/' . rawurlencode((string) $row['id']),
        ], $stmt->fetchAll());
    }

    private function closureDossiers(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cr.id, cr.case_id, cr.closure_no, cr.closure_reason, cr.dossier_sha256, cr.closed_at,
                    c.public_number
             FROM case_closure_records cr
             INNER JOIN cases c ON c.id = cr.case_id
             WHERE c.user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        return array_map(static fn(array $row): array => [
            'type' => 'CLOSURE_DOSSIER',
            'id' => $row['id'],
            'case_id' => $row['case_id'],
            'public_number' => $row['public_number'],
            'title' => 'Abschlussakte ' . $row['public_number'] . ' #' . $row['closure_no'],
            'filename' => $row['public_number'] . '_Abschlussakte_' . str_pad((string) $row['closure_no'], 2, '0', STR_PAD_LEFT) . '.json',
            'version' => (int) $row['closure_no'],
            'mime_type' => 'application/json',
            'file_size' => null,
            'sha256' => $row['dossier_sha256'],
            'created_at' => $row['closed_at'],
            'open_url' => '/cases/' . rawurlencode((string) $row['case_id']) . '/lifecycle',
            'download_url' => '/cases/' . rawurlencode((string) $row['case_id']) . '/lifecycle/closures/' . rawurlencode((string) $row['id']) . '/export',
        ], $stmt->fetchAll());
    }

    private function municipalReports(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, version_no, title, report_sha256, created_at
             FROM municipal_reports
             WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        return array_map(static fn(array $row): array => [
            'type' => 'MUNICIPAL_REPORT',
            'id' => $row['id'],
            'case_id' => null,
            'public_number' => null,
            'title' => $row['title'],
            'filename' => null,
            'version' => (int) $row['version_no'],
            'mime_type' => 'application/json',
            'file_size' => null,
            'sha256' => $row['report_sha256'],
            'created_at' => $row['created_at'],
            'open_url' => '/municipal-reports/' . rawurlencode((string) $row['id']),
            'download_url' => null,
        ], $stmt->fetchAll());
    }

    private function exports(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, export_type, format, filename, mime_type, file_size, sha256, created_at
             FROM export_artifacts
             WHERE user_id = :user_id AND status = "READY"'
        );
        $stmt->execute(['user_id' => $userId]);

        return array_map(static fn(array $row): array => [
            'type' => 'EXPORT',
            'id' => $row['id'],
            'case_id' => null,
            'public_number' => null,
            'title' => 'Export ' . $row['export_type'] . ' (' . strtoupper((string) $row['format']) . ')',
            'filename' => $row['filename'],
            'version' => null,
            'mime_type' => $row['mime_type'],
            'file_size' => (int) $row['file_size'],
            'sha256' => $row['sha256'],
            'created_at' => $row['created_at'],
            'open_url' => null,
            'download_url' => '/exports/' . rawurlencode((string) $row['id']),
        ], $stmt->fetchAll());
    }
}
