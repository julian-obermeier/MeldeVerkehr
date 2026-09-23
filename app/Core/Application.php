<?php

declare(strict_types=1);

namespace MeldeVerkehr\Core;

use MeldeVerkehr\Config\Config;
use MeldeVerkehr\Database\Connection;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Routing\Router;
use PDO;
use Throwable;

final class Application
{
    private ?PDO $database = null;

    public function __construct(
        private readonly Router $router,
        private readonly Config $config,
        private readonly string $basePath,
        private readonly bool $debug = false
    ) {
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function database(): PDO
    {
        if ($this->database === null) {
            $this->database = Connection::make((array) $this->config->get('database', []));
        }

        return $this->database;
    }

    public function run(Request $request): void
    {
        $requestId = bin2hex(random_bytes(8));

        try {
            $this->router->dispatch($request)->send();
        } catch (Throwable $e) {
            $this->logException($requestId, $e);

            $detail = $this->debug
                ? '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>'
                : '';

            Response::html(
                '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Fehler</title></head><body><h1>Interner Fehler</h1><p>Die Anfrage konnte nicht verarbeitet werden.</p><p>Referenz: <code>' .
                htmlspecialchars($requestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
                '</code></p>' . $detail . '</body></html>',
                500
            )->send();
        }
    }

    private function logException(string $requestId, Throwable $e): void
    {
        $directory = $this->basePath . '/storage/logs';

        if (!is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }

        $line = sprintf(
            "[%s] request_id=%s %s: %s in %s:%d\n",
            date(DATE_ATOM),
            $requestId,
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        @file_put_contents($directory . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}
