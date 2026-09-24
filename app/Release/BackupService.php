<?php

declare(strict_types=1);

namespace MeldeVerkehr\Release;

use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class BackupService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SecretCipher $cipher,
        private readonly string $backupRoot,
        private readonly string $runtimeRoot,
        private readonly int $maxFileBytes = 52428800
    ) {
    }

    public function create(?string $actorUserId = null, bool $includeRuntime = true): array
    {
        $id = Uuid::v4();
        $directory = rtrim($this->backupRoot, '/\\') . '/' . $id;

        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Backup-Verzeichnis konnte nicht erstellt werden.');
        }

        $this->pdo->prepare(
            'INSERT INTO system_backups
             (id, backup_type, status, storage_path, manifest_sha256, database_bytes,
              runtime_files, runtime_bytes, encrypted, created_by_user_id,
              created_at, completed_at, last_error)
             VALUES
             (:id, :type, "CREATING", :path, NULL, 0, 0, 0, 1, :user_id,
              UTC_TIMESTAMP(), NULL, NULL)'
        )->execute([
            'id' => $id,
            'type' => $includeRuntime ? 'FULL' : 'DATABASE',
            'path' => $id,
            'user_id' => $actorUserId,
        ]);

        try {
            $database = $this->backupDatabase($directory);
            $runtime = $includeRuntime
                ? $this->backupRuntime($directory)
                : ['files' => [], 'bytes' => 0];

            $manifest = [
                'schema_version' => 1,
                'backup_id' => $id,
                'created_at' => gmdate('c'),
                'encrypted' => true,
                'database' => $database,
                'runtime' => [
                    'root' => 'storage/app',
                    'files' => $runtime['files'],
                    'bytes' => $runtime['bytes'],
                ],
            ];

            $manifestJson = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $manifestHash = hash('sha256', $manifestJson);

            if (file_put_contents($directory . '/manifest.json', $manifestJson, LOCK_EX) === false) {
                throw new \RuntimeException('Backup-Manifest konnte nicht geschrieben werden.');
            }

            $this->pdo->prepare(
                'UPDATE system_backups
                 SET status = "READY", manifest_sha256 = :manifest_sha256,
                     database_bytes = :database_bytes, runtime_files = :runtime_files,
                     runtime_bytes = :runtime_bytes, completed_at = UTC_TIMESTAMP(),
                     last_error = NULL
                 WHERE id = :id'
            )->execute([
                'manifest_sha256' => $manifestHash,
                'database_bytes' => $database['plaintext_bytes'],
                'runtime_files' => count($runtime['files']),
                'runtime_bytes' => $runtime['bytes'],
                'id' => $id,
            ]);

            return [
                'id' => $id,
                'status' => 'READY',
                'storage_path' => $id,
                'manifest_sha256' => $manifestHash,
                'database_bytes' => $database['plaintext_bytes'],
                'runtime_files' => count($runtime['files']),
                'runtime_bytes' => $runtime['bytes'],
            ];
        } catch (Throwable $e) {
            $this->pdo->prepare(
                'UPDATE system_backups
                 SET status = "FAILED", last_error = :error, completed_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            )->execute([
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'id' => $id,
            ]);
            throw $e;
        }
    }

    public function verify(string $backupId): array
    {
        $backup = $this->loadBackup($backupId);
        $directory = $this->backupDirectory((string) $backup['storage_path']);
        $manifestPath = $directory . '/manifest.json';

        if (!is_file($manifestPath)) {
            throw new \RuntimeException('Backup-Manifest fehlt.');
        }

        $manifestJson = (string) file_get_contents($manifestPath);
        if (!hash_equals((string) $backup['manifest_sha256'], hash('sha256', $manifestJson))) {
            throw new \RuntimeException('Backup-Manifest-Integrität ist verletzt.');
        }

        $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['backup_id'] ?? null) !== $backupId) {
            throw new \RuntimeException('Backup-Manifest ist ungültig.');
        }

        $databasePlain = $this->decryptFile(
            $directory . '/' . (string) $manifest['database']['encrypted_path']
        );

        if (!hash_equals(
            (string) $manifest['database']['sha256'],
            hash('sha256', $databasePlain)
        )) {
            throw new \RuntimeException('Datenbankbackup-Integrität ist verletzt.');
        }

        $verifiedRuntime = 0;
        foreach ($manifest['runtime']['files'] ?? [] as $file) {
            if (!is_array($file)) {
                throw new \RuntimeException('Runtime-Manifest ist ungültig.');
            }

            $plain = $this->decryptFile(
                $directory . '/' . (string) $file['encrypted_path']
            );

            if (!hash_equals((string) $file['sha256'], hash('sha256', $plain))) {
                throw new \RuntimeException('Runtime-Backup-Integrität ist verletzt.');
            }
            $verifiedRuntime++;
        }

        return [
            'id' => $backupId,
            'status' => 'VERIFIED',
            'database_sha256' => (string) $manifest['database']['sha256'],
            'runtime_files_verified' => $verifiedRuntime,
            'runtime_bytes' => (int) ($manifest['runtime']['bytes'] ?? 0),
        ];
    }

    public function list(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return $this->pdo->query(
            'SELECT id, backup_type, status, storage_path, manifest_sha256,
                    database_bytes, runtime_files, runtime_bytes, encrypted,
                    created_at, completed_at, last_error
             FROM system_backups
             ORDER BY created_at DESC LIMIT ' . $limit
        )->fetchAll();
    }

    public function restore(string $backupId, bool $confirmed): array
    {
        if (!$confirmed) {
            throw new \InvalidArgumentException('Restore erfordert explizite Bestätigung.');
        }

        $this->verify($backupId);
        $backup = $this->loadBackup($backupId);
        $directory = $this->backupDirectory((string) $backup['storage_path']);
        $manifest = json_decode(
            (string) file_get_contents($directory . '/manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $databasePlain = $this->decryptFile(
            $directory . '/' . (string) $manifest['database']['encrypted_path']
        );

        $staging = $directory . '/restore-staging';
        $this->removeDirectory($staging);

        if (!mkdir($staging, 0770, true) && !is_dir($staging)) {
            throw new \RuntimeException('Restore-Staging konnte nicht erstellt werden.');
        }

        foreach ($manifest['runtime']['files'] ?? [] as $file) {
            $relative = $this->safeRelativePath((string) $file['path']);
            $plain = $this->decryptFile(
                $directory . '/' . (string) $file['encrypted_path']
            );
            $target = $staging . '/' . $relative;
            $parent = dirname($target);

            if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
                throw new \RuntimeException('Restore-Verzeichnis konnte nicht erstellt werden.');
            }

            if (file_put_contents($target, $plain, LOCK_EX) === false) {
                throw new \RuntimeException('Restore-Staging konnte nicht geschrieben werden.');
            }
        }

        $this->restoreDatabase($databasePlain);

        foreach ($manifest['runtime']['files'] ?? [] as $file) {
            $relative = $this->safeRelativePath((string) $file['path']);
            $source = $staging . '/' . $relative;
            $target = rtrim($this->runtimeRoot, '/\\') . '/' . $relative;
            $parent = dirname($target);

            if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
                throw new \RuntimeException('Runtime-Zielverzeichnis konnte nicht erstellt werden.');
            }

            $body = (string) file_get_contents($source);
            if (file_put_contents($target, $body, LOCK_EX) === false) {
                throw new \RuntimeException('Runtime-Datei konnte nicht wiederhergestellt werden.');
            }
        }

        $this->removeDirectory($staging);

        return [
            'id' => $backupId,
            'status' => 'RESTORED',
            'runtime_files' => count($manifest['runtime']['files'] ?? []),
        ];
    }

    private function backupDatabase(string $directory): array
    {
        $plainPath = $directory . '/database.jsonl.tmp';
        $handle = fopen($plainPath, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Datenbank-Backupdatei konnte nicht erstellt werden.');
        }

        try {
            fwrite($handle, json_encode([
                'type' => 'meta',
                'schema_version' => 1,
                'created_at' => gmdate('c'),
            ], JSON_THROW_ON_ERROR) . "\n");

            $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $tableName) {
                $table = (string) $tableName;
                $quoted = $this->quoteIdentifier($table);
                $create = $this->pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);

                if (!is_array($create) || !isset($create[1])) {
                    throw new \RuntimeException('CREATE TABLE konnte nicht gelesen werden: ' . $table);
                }

                fwrite($handle, json_encode([
                    'type' => 'schema',
                    'table' => $table,
                    'create_sql_b64' => base64_encode((string) $create[1]),
                ], JSON_THROW_ON_ERROR) . "\n");

                $stmt = $this->pdo->query('SELECT * FROM ' . $quoted);

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $encoded = [];
                    foreach ($row as $column => $value) {
                        $encoded[(string) $column] = $value === null
                            ? null
                            : base64_encode((string) $value);
                    }

                    fwrite($handle, json_encode([
                        'type' => 'row',
                        'table' => $table,
                        'values_b64' => $encoded,
                    ], JSON_THROW_ON_ERROR) . "\n");
                }
            }
        } finally {
            fclose($handle);
        }

        $bytes = filesize($plainPath);

        if ($bytes === false || $bytes > $this->maxFileBytes) {
            @unlink($plainPath);
            throw new \RuntimeException('Datenbankbackup überschreitet die sichere Backup-Größenobergrenze.');
        }

        $plain = (string) file_get_contents($plainPath);
        $hash = hash('sha256', $plain);
        $encryptedPath = $directory . '/database.jsonl.enc';

        if (file_put_contents($encryptedPath, $this->cipher->encrypt($plain), LOCK_EX) === false) {
            @unlink($plainPath);
            throw new \RuntimeException('Verschlüsseltes Datenbankbackup konnte nicht geschrieben werden.');
        }

        @unlink($plainPath);

        return [
            'encrypted_path' => 'database.jsonl.enc',
            'sha256' => $hash,
            'plaintext_bytes' => (int) $bytes,
        ];
    }

    private function backupRuntime(string $directory): array
    {
        if (!is_dir($this->runtimeRoot)) {
            return ['files' => [], 'bytes' => 0];
        }

        $targetRoot = $directory . '/runtime';

        if (!mkdir($targetRoot, 0770, true) && !is_dir($targetRoot)) {
            throw new \RuntimeException('Runtime-Backupverzeichnis konnte nicht erstellt werden.');
        }

        $files = [];
        $totalBytes = 0;
        $root = rtrim($this->runtimeRoot, '/\\');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $absolute = $fileInfo->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($root))), '/');

            if ($relative === '' || str_starts_with($relative, 'backups/')) {
                continue;
            }

            $size = $fileInfo->getSize();

            if ($size > $this->maxFileBytes) {
                throw new \RuntimeException('Runtime-Datei überschreitet Backup-Limit: ' . $relative);
            }

            $plain = (string) file_get_contents($absolute);
            $hash = hash('sha256', $plain);
            $encryptedPath = 'runtime/' . hash('sha256', $relative) . '.enc';

            if (file_put_contents(
                $directory . '/' . $encryptedPath,
                $this->cipher->encrypt($plain),
                LOCK_EX
            ) === false) {
                throw new \RuntimeException('Runtime-Datei konnte nicht gesichert werden: ' . $relative);
            }

            $files[] = [
                'path' => $relative,
                'encrypted_path' => $encryptedPath,
                'sha256' => $hash,
                'bytes' => $size,
            ];
            $totalBytes += $size;
        }

        return ['files' => $files, 'bytes' => $totalBytes];
    }

    private function restoreDatabase(string $jsonl): void
    {
        $lines = preg_split('/\r?\n/', $jsonl);

        if (!is_array($lines)) {
            throw new \RuntimeException('Datenbankbackup ist ungültig.');
        }

        $schemas = [];
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($entry)) {
                throw new \RuntimeException('Datenbankbackup enthält ungültige Einträge.');
            }

            if (($entry['type'] ?? null) === 'schema') {
                $decoded = base64_decode((string) $entry['create_sql_b64'], true);

                if (!is_string($decoded) || $decoded === '') {
                    throw new \RuntimeException('Tabellenschema ist ungültig.');
                }

                $schemas[(string) $entry['table']] = $decoded;
            } elseif (($entry['type'] ?? null) === 'row') {
                $rows[] = $entry;
            }
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($schemas as $table => $createSql) {
                $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table));
                $this->pdo->exec($createSql);
            }

            foreach ($rows as $entry) {
                $table = (string) $entry['table'];
                $values = $entry['values_b64'] ?? [];

                if (!is_array($values) || $values === []) {
                    continue;
                }

                $columns = array_keys($values);
                $quotedColumns = array_map(
                    fn(string $column): string => $this->quoteIdentifier($column),
                    $columns
                );
                $placeholders = [];
                $params = [];

                foreach ($columns as $index => $column) {
                    $key = 'v' . $index;
                    $placeholders[] = ':' . $key;
                    $encoded = $values[$column];
                    $params[$key] = $encoded === null
                        ? null
                        : base64_decode((string) $encoded, true);
                }

                $stmt = $this->pdo->prepare(
                    'INSERT INTO ' . $this->quoteIdentifier($table) .
                    ' (' . implode(',', $quotedColumns) . ')' .
                    ' VALUES (' . implode(',', $placeholders) . ')'
                );
                $stmt->execute($params);
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function decryptFile(string $path): string
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Backup-Datei fehlt: ' . basename($path));
        }

        return $this->cipher->decrypt((string) file_get_contents($path));
    }

    private function loadBackup(string $backupId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM system_backups WHERE id = :id AND status = "READY" LIMIT 1'
        );
        $stmt->execute(['id' => $backupId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new \DomainException('Verwendbares Backup nicht gefunden.');
        }

        return $row;
    }

    private function backupDirectory(string $relative): string
    {
        return rtrim($this->backupRoot, '/\\') . '/' . $this->safeRelativePath($relative);
    }

    private function safeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '../')) {
            throw new \RuntimeException('Unsicherer Backup-Pfad.');
        }

        return $path;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return chr(96) . str_replace(chr(96), chr(96) . chr(96), $identifier) . chr(96);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
