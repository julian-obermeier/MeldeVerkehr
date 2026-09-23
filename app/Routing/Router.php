<?php

declare(strict_types=1);

namespace MeldeVerkehr\Routing;

use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;

final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function add(string $method, string $path, callable $handler): void
    {
        $normalized = '/' . trim($path, '/');
        if ($normalized === '/') {
            $normalized = '/';
        }

        $this->routes[strtoupper($method)][$normalized] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $handler = $this->routes[$request->method()][$request->path()] ?? null;

        if ($handler === null) {
            return Response::html(
                '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>404</title></head><body><h1>404</h1><p>Die angeforderte Seite wurde nicht gefunden.</p></body></html>',
                404
            );
        }

        $response = $handler($request);

        if (!$response instanceof Response) {
            throw new \RuntimeException('Route handler must return a Response instance.');
        }

        return $response;
    }
}
