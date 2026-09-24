<?php

declare(strict_types=1);

namespace MeldeVerkehr\Update;

final class BackupService
{
    public function __construct(
        private readonly string $basePath,
        private readonly array $database
    ) {
    }

    public function capability(): array
    {
        return [
            'storage_writable' => $this->ensureBackupRoot(false),
            'mysqldump_path' => $this->findBinary(['mysqldump', 'mariadb-dump']),
            'proc_open_available' => function_exists('proc_open'),
        ];
    }

    public function create(bool $requireDatabase = true): array
    {
        $this->ensureBackupRoot(true);

        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $relativeDirectory = 'backups/' . $id;
        $directory = $this->basePath . '/storage/' . $relativeDirectory;

        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Backup-Verzeichnis konnte nicht angelegt werden.');
        }

        @chmod($directory, 0700);

        $files = [];

        foreach ([
            '.env' => '.env',
            'VERSION' => 'VERSION',
            'storage/app/installed.lock' => 'installed.lock',
        ] as $source => $target) {
            $sourcePath = $this->basePath . '/' . $source;

            if (!is_file($sourcePath)) {
                if ($source === '.env') {
                    throw new \RuntimeException('.env fehlt; sicheres Backup kann nicht erstellt werden.');
                }
                continue;
            }

            $targetPath = $directory . '/' . $target;
            if (!copy($sourcePath, $targetPath)) {
                throw new \RuntimeException('Backup-Datei konnte nicht kopiert werden: ' . $source);
            }
            @chmod($targetPath, 0600);
            $files[] = $this->metadata($targetPath, $relativeDirectory . '/' . $target);
        }

        $databaseBackup = null;
        $dumpBinary = $this->findBinary(['mysqldump', 'mariadb-dump']);

        if ($dumpBinary !== null && function_exists('proc_open')) {
            $databaseBackup = $this->dumpDatabase($dumpBinary, $directory, $relativeDirectory);
            $files[] = $databaseBackup;
        } elseif ($requireDatabase) {
            $this->removeDirectory($directory);
            throw new \RuntimeException(
                'Kein nutzbares mysqldump/mariadb-dump verfügbar. Update wird ohne DB-Backup nicht fortgesetzt.'
            );
        }

        $manifest = [
            'schema_version' => 1,
            'backup_id' => $id,
            'created_at' => gmdate(DATE_ATOM),
            'version' => trim((string) @file_get_contents($this->basePath . '/VERSION')),
            'database_included' => $databaseBackup !== null,
            'files' => $files,
        ];

        $manifestJson = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        $manifestPath = $directory . '/manifest.json';

        if (file_put_contents($manifestPath, $manifestJson, LOCK_EX) === false) {
            throw new \RuntimeException('Backup-Manifest konnte nicht gespeichert werden.');
        }
        @chmod($manifestPath, 0600);

        $manifest['manifest_sha256'] = hash('sha256', $manifestJson);

        return $manifest;
    }

    public function restore(string $backupId, bool $restoreDatabase = true): array
    {
        $verification = $this->verify($backupId);

        if (!$verification['ok']) {
            throw new \RuntimeException('Backup-Verifikation ist fehlgeschlagen; Restore wird abgebrochen.');
        }

        $directory = $this->basePath . '/storage/backups/' . $backupId;
        $restored = [];

        if ($restoreDatabase) {
            $databaseSql = $directory . '/database.sql';

            if (!is_file($databaseSql)) {
                throw new \RuntimeException('Backup enthält keinen Datenbank-Dump.');
            }

            $client = $this->findBinary(['mysql', 'mariadb']);
            if ($client === null || !function_exists('proc_open')) {
                throw new \RuntimeException('Kein mysql/mariadb-Client für Restore verfügbar.');
            }

            $this->restoreDatabase($client, $databaseSql);
            $restored[] = 'database';
        }

        foreach ([
            '.env' => '.env',
            'installed.lock' => 'storage/app/installed.lock',
        ] as $backupName => $targetRelative) {
            $source = $directory . '/' . $backupName;

            if (!is_file($source)) {
                continue;
            }

            $target = $this->basePath . '/' . $targetRelative;
            $targetDirectory = dirname($target);

            if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0770, true) && !is_dir($targetDirectory)) {
                throw new \RuntimeException('Restore-Zielverzeichnis konnte nicht angelegt werden.');
            }

            $temporary = $target . '.restore-' . bin2hex(random_bytes(4));
            if (!copy($source, $temporary)) {
                throw new \RuntimeException('Restore-Datei konnte nicht vorbereitet werden: ' . $backupName);
            }
            @chmod($temporary, 0600);

            if (!rename($temporary, $target)) {
                @unlink($temporary);
                throw new \RuntimeException('Restore-Datei konnte nicht atomar ersetzt werden: ' . $targetRelative);
            }

            @chmod($target, 0600);
            $restored[] = $targetRelative;
        }

        return [
            'status' => 'RESTORED',
            'backup_id' => $backupId,
            'database_restored' => $restoreDatabase,
            'restored' => $restored,
        ];
    }

    public function verify(string $backupId): array
    {
        if (!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/', $backupId)) {
            throw new \InvalidArgumentException('Ungültige Backup-ID.');
        }

        $directory = $this->basePath . '/storage/backups/' . $backupId;
        $manifestPath = $directory . '/manifest.json';

        if (!is_file($manifestPath)) {
            throw new \DomainException('Backup-Manifest nicht gefunden.');
        }

        $json = file_get_contents($manifestPath);
        if ($json === false) {
            throw new \RuntimeException('Backup-Manifest konnte nicht gelesen werden.');
        }

        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new \RuntimeException('Backup-Manifest ist ungültig.');
        }

        $errors = [];

        foreach ($manifest['files'] ?? [] as $file) {
            $relative = (string) ($file['path'] ?? '');
            if (!str_starts_with($relative, 'backups/' . $backupId . '/')) {
                $errors[] = 'Ungültiger Manifestpfad.';
                continue;
            }

            $absolute = $this->basePath . '/storage/' . $relative;
            if (!is_file($absolute)) {
                $errors[] = 'Datei fehlt: ' . $relative;
                continue;
            }

            $hash = hash_file('sha256', $absolute);
            if (!hash_equals((string) ($file['sha256'] ?? ''), $hash)) {
                $errors[] = 'Hash stimmt nicht: ' . $relative;
            }

            if ((int) ($file['size'] ?? -1) !== filesize($absolute)) {
                $errors[] = 'Dateigröße stimmt nicht: ' . $relative;
            }
        }

        return [
            'ok' => $errors === [],
            'backup_id' => $backupId,
            'database_included' => (bool) ($manifest['database_included'] ?? false),
            'errors' => $errors,
            'manifest' => $manifest,
        ];
    }

    private function dumpDatabase(
        string $binary,
        string $directory,
        string $relativeDirectory
    ): array {
        $host = trim((string) ($this->database['host'] ?? ''));
        $port = (int) ($this->database['port'] ?? 3306);
        $database = trim((string) ($this->database['database'] ?? ''));
        $username = trim((string) ($this->database['username'] ?? ''));
        $password = (string) ($this->database['password'] ?? '');

        if ($host === '' || $database === '' || $username === '') {
            throw new \RuntimeException('Datenbankkonfiguration ist für Backup unvollständig.');
        }

        $target = $directory . '/database.sql';

        $command = [
            $binary,
            '--host=' . $host,
            '--port=' . $port,
            '--user=' . $username,
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--default-character-set=utf8mb4',
            '--result-file=' . $target,
            $database,
        ];

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['MYSQL_PWD'] = $password;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->basePath, $environment);

        if (!is_resource($process)) {
            throw new \RuntimeException('Datenbank-Dump konnte nicht gestartet werden.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || !is_file($target) || filesize($target) === 0) {
            @unlink($target);
            throw new \RuntimeException(
                'Datenbank-Dump fehlgeschlagen: ' . mb_substr(trim($stderr . ' ' . $stdout), 0, 1200)
            );
        }

        @chmod($target, 0600);

        return $this->metadata($target, $relativeDirectory . '/database.sql');
    }

    private function restoreDatabase(string $binary, string $sqlFile): void
    {
        $host = trim((string) ($this->database['host'] ?? ''));
        $port = (int) ($this->database['port'] ?? 3306);
        $database = trim((string) ($this->database['database'] ?? ''));
        $username = trim((string) ($this->database['username'] ?? ''));
        $password = (string) ($this->database['password'] ?? '');

        if ($host === '' || $database === '' || $username === '') {
            throw new \RuntimeException('Datenbankkonfiguration ist für Restore unvollständig.');
        }

        $command = [
            $binary,
            '--host=' . $host,
            '--port=' . $port,
            '--user=' . $username,
            '--default-character-set=utf8mb4',
            $database,
        ];

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['MYSQL_PWD'] = $password;

        $process = proc_open(
            $command,
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']],
            $pipes,
            $this->basePath,
            $environment
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Datenbank-Restore konnte nicht gestartet werden.');
        }

        $input = fopen($sqlFile, 'rb');
        if ($input === false) {
            proc_terminate($process);
            throw new \RuntimeException('Datenbank-Dump konnte nicht gelesen werden.');
        }

        stream_copy_to_stream($input, $pipes[0]);
        fclose($input);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                'Datenbank-Restore fehlgeschlagen: '
                . mb_substr(trim($stderr . ' ' . $stdout), 0, 1200)
            );
        }
    }

    private function metadata(string $absolute, string $relative): array
    {
        return [
            'path' => $relative,
            'size' => filesize($absolute),
            'sha256' => hash_file('sha256', $absolute),
        ];
    }

    private function findBinary(array $names): ?string
    {
        $directories = array_values(array_unique(array_filter(array_merge(
            explode(PATH_SEPARATOR, (string) getenv('PATH')),
            ['/usr/bin','/usr/local/bin','/bin','/usr/sbin','/usr/local/sbin']
        ))));

        foreach ($names as $name) {
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
                continue;
            }

            foreach ($directories as $directory) {
                $candidate = rtrim($directory, '/\\') . '/' . $name;
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function ensureBackupRoot(bool $throw): bool
    {
        $root = $this->basePath . '/storage/backups';

        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            if ($throw) {
                throw new \RuntimeException('Backup-Root konnte nicht angelegt werden.');
            }
            return false;
        }

        @chmod($root, 0700);

        $ok = is_writable($root);
        if (!$ok && $throw) {
            throw new \RuntimeException('Backup-Root ist nicht schreibbar.');
        }

        return $ok;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (array_diff(scandir($directory) ?: [], ['.','..']) as $entry) {
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
