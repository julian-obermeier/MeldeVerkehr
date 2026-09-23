<?php

declare(strict_types=1);

namespace MeldeVerkehr\Database;

use PDO;
use PDOException;

final class Connection
{
    public static function make(array $config): PDO
    {
        self::validate($config);

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            (int) $config['port'],
            $config['database'],
            $config['charset'] ?? 'utf8mb4'
        );

        return new PDO(
            $dsn,
            (string) $config['username'],
            (string) $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]
        );
    }

    public static function test(array $config): array
    {
        try {
            $pdo = self::make($config);
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

            return [
                'ok' => true,
                'version' => $version,
                'error' => null,
            ];
        } catch (PDOException|\InvalidArgumentException $e) {
            return [
                'ok' => false,
                'version' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private static function validate(array $config): void
    {
        foreach (['host', 'database', 'username'] as $key) {
            if (!isset($config[$key]) || trim((string) $config[$key]) === '') {
                throw new \InvalidArgumentException(sprintf('Database configuration "%s" is required.', $key));
            }
        }

        $port = (int) ($config['port'] ?? 0);

        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Database port must be between 1 and 65535.');
        }
    }
}
