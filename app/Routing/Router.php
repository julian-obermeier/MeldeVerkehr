<?php

declare(strict_types=1);

namespace MeldeVerkehr\Routing;

use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;

final class Router
{
    /** @var array<string, array<int, array{path:string,regex:string,handler:callable}>> */
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

        $this->routes[strtoupper($method)][] = [
            'path' => $normalized,
            'regex' => $this->compile($normalized),
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes[$request->method()] ?? [] as $route) {
            if (!preg_match($route['regex'], $request->path(), $matches)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode((string) $value);
                }
            }

            $request->setRouteParams($params);
            $response = ($route['handler'])($request);

            if (!$response instanceof Response) {
                throw new \RuntimeException('Route handler must return a Response instance.');
            }

            return $response;
        }

        return Response::html(
            '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>404</title></head><body><h1>404</h1><p>Die angeforderte Seite wurde nicht gefunden.</p></body></html>',
            404
        );
    }

    private function compile(string $path): string
    {
        if ($path === '/') {
            return '#^/$#';
        }

        $segments = explode('/', trim($path, '/'));
        $compiled = [];

        foreach ($segments as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $match)) {
                $compiled[] = '(?P<' . $match[1] . '>[^/]+)';
            } else {
                $compiled[] = preg_quote($segment, '#');
            }
        }

        return '#^/' . implode('/', $compiled) . '$#';
    }
}
