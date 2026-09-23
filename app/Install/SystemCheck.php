<?php

declare(strict_types=1);

namespace MeldeVerkehr\Install;

final class SystemCheck
{
    public function run(string $basePath): array
    {
        $checks = [
            'PHP >= 8.3' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'PDO' => extension_loaded('pdo'),
            'PDO MySQL' => extension_loaded('pdo_mysql'),
            'OpenSSL' => extension_loaded('openssl'),
            'mbstring' => extension_loaded('mbstring'),
            'fileinfo' => extension_loaded('fileinfo'),
            'JSON' => extension_loaded('json'),
            'GD oder Imagick' => extension_loaded('gd') || extension_loaded('imagick'),
            'storage beschreibbar' => $this->isWritableDirectory($basePath . '/storage'),
            'Projektverzeichnis beschreibbar (.env)' => is_writable($basePath),
        ];

        return [
            'checks' => $checks,
            'ok' => !in_array(false, $checks, true),
        ];
    }

    private function isWritableDirectory(string $path): bool
    {
        if (!is_dir($path) && !@mkdir($path, 0770, true) && !is_dir($path)) {
            return false;
        }

        return is_writable($path);
    }
}
