<?php
// src/Core/Router.php

declare(strict_types=1);

namespace ZbxDeploy\Core;

/**
 * Minimal router. Routes are declared as "GET /hosts/{id}" and matched with a
 * generated regex; {id} is passed to the controller method as an argument.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, params: list<string>, handler: callable|array{0: class-string, 1: string}, auth: bool}> */
    private array $routes = [];

    /** @param callable|array{0: class-string, 1: string} $handler */
    public function get(string $path, callable|array $handler, bool $auth = true): void
    {
        $this->add('GET', $path, $handler, $auth);
    }

    /** @param callable|array{0: class-string, 1: string} $handler */
    public function post(string $path, callable|array $handler, bool $auth = true): void
    {
        $this->add('POST', $path, $handler, $auth);
    }

    /** @param callable|array{0: class-string, 1: string} $handler */
    private function add(string $method, string $path, callable|array $handler, bool $auth): void
    {
        $params = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];

                return '([^/]+)';
            },
            $path
        );

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
            'auth'    => $auth,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            if ($route['auth']) {
                Auth::requireLogin();
            }

            array_shift($matches);
            $handler = $route['handler'];

            if (is_array($handler)) {
                [$class, $action] = $handler;
                $controller = new $class();
                $controller->{$action}(...$matches);

                return;
            }

            $handler(...$matches);

            return;
        }

        http_response_code(404);
        View::send('error.twig', [
            'title'   => 'Not found',
            'message' => sprintf('No route matches %s %s', $method, $path),
        ]);
    }
}
