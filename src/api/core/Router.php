<?php
namespace App\Core;

use App\Core\Exceptions\NotFoundException;

class Router
{
    private $routes = [];
    private $middlewareRegistry = [];
    private $currentGroup = [];

    public function __construct(private $basePath = '')
    {
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Register middleware by name
     */
    public function registerMiddleware(string $name, string $class): void
    {
        $this->middlewareRegistry[$name] = $class;
    }

    /**
     * Route grouping with shared attributes
     */
    public function group(array $options, callable $callback): void
    {
        $previousGroup = $this->currentGroup;

        // Normalize middleware option
        $middleware = [];
        if (isset($options['middleware'])) {
            if (is_array($options['middleware'])) {
                $middleware = $options['middleware'];
            } elseif (!empty($options['middleware'])) {
                $middleware = [$options['middleware']];
            }
        }

        // Normalize before filters
        $before = [];
        if (isset($options['before'])) {
            $before = is_array($options['before']) ? $options['before'] : [$options['before']];
        }

        // Normalize after filters
        $after = [];
        if (isset($options['after'])) {
            $after = is_array($options['after']) ? $options['after'] : [$options['after']];
        }

        // Normalize tags/group
        $tags = [];
        if (isset($options['group'])) {
            $tags = is_array($options['group']) ? $options['group'] : [$options['group']];
        }

        // Merge group options
        $this->currentGroup = [
            'prefix' => ($previousGroup['prefix'] ?? '') . ($options['prefix'] ?? ''),
            'middleware' => array_merge($previousGroup['middleware'] ?? [], $middleware),
            'before' => array_merge($previousGroup['before'] ?? [], $before),
            'after' => array_merge($previousGroup['after'] ?? [], $after),
            'tags' => array_merge($previousGroup['tags'] ?? [], $tags)
        ];

        $callback($this);

        $this->currentGroup = $previousGroup;
    }


    /**
     * Enhanced add method with middleware support
     */
    public function add(array $config): void
    {
        // Apply group prefix
        $pattern = $config['url'] ?? $config['pattern'] ?? '';
        if (!empty($this->currentGroup['prefix'])) {
            $pattern = rtrim($this->currentGroup['prefix'], '/') . '/' . ltrim($pattern, '/');
        }

        // Merge middleware from group and route
        $routeMiddleware = $config['middleware'] ?? [];
        if (!is_array($routeMiddleware)) {
            $routeMiddleware = $routeMiddleware ? [$routeMiddleware] : [];
        }

        $middleware = array_merge(
            $this->currentGroup['middleware'] ?? [],
            $routeMiddleware
        );

        // Merge before/after filters
        $before = array_merge(
            $this->currentGroup['before'] ?? [],
            $config['before'] ?? []
        );

        $after = array_merge(
            $this->currentGroup['after'] ?? [],
            $config['after'] ?? []
        );

        // Merge tags
        $tags = $config['group'] ?? $config['tags'] ?? [];
        if (!is_array($tags)) {
            $tags = $tags ? [$tags] : [];
        }
        $tags = array_merge($this->currentGroup['tags'] ?? [], $tags);

        // Extract with defaults
        $method = strtoupper($config['method'] ?? 'GET');
        $handler = $config['controller'] ?? $config['handler'] ?? '';
        $description = $config['desc'] ?? $config['description'] ?? '';
        $visible = $config['visible'] ?? true;
        $showHeaders = $config['showHeaders'] ?? false;

        // Enhanced parameter handling
        $params = $config['params'] ?? [];
        $urlParams = $params['url'] ?? [];
        $getParams = $params['get'] ?? [];
        $formParams = $params['form'] ?? $params['post'] ?? [];
        $jsonParams = $params['json'] ?? $params['body'] ?? [];

        $route = [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handler,
            'description' => $description,
            'visible' => $visible,
            'showHeaders' => $showHeaders,
            'tags' => $tags,
            'params' => [
                'url' => $urlParams,
                'get' => $getParams,
                'form' => $formParams,
                'json' => $jsonParams
            ],
            'regex' => $this->patternToRegex($pattern),
            'specificity' => $this->calculateSpecificity($pattern),
            'middleware' => $middleware,
            'before' => $before,
            'after' => $after
        ];

        $this->routes[] = $route;
        usort($this->routes, fn($a, $b) => $b['specificity'] <=> $a['specificity']);
    }

    /**
     * Dispatch with middleware execution
     */
    public function dispatch(): void
    {
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

            // Remove base path properly
            if (!empty($this->basePath)) {
                $basePath = '/' . trim($this->basePath, '/');
                if (strpos($uri, $basePath) === 0) {
                    $uri = substr($uri, strlen($basePath));
                }
            }

            // Ensure URI starts with /
            $uri = '/' . trim($uri, '/');
            if ($uri === '/')
                $uri = '/';

            foreach ($this->routes as $route) {
                if ($method !== $route['method'])
                    continue;

                if (preg_match($route['regex'], $uri, $matches)) {
                    array_shift($matches);

                    // Execute BEFORE middleware/filters
                    $beforeResult = $this->executeBeforeMiddleware($route);
                    if ($beforeResult !== null) {
                        return; // Middleware halted execution
                    }

                    // Execute controller
                    [$controller, $action] = explode('@', $route['handler']);
                    $controllerClass = "App\\Controllers\\{$controller}";

                    if (!class_exists($controllerClass)) {
                        throw new \Exception("Controller not found: {$controllerClass}");
                    }

                    $controllerInstance = new $controllerClass();

                    // Set router for DocsController
                    if ($controller === 'DocsController') {
                        $controllerInstance->setRouter($this);
                    }

                    if (!method_exists($controllerInstance, $action)) {
                        throw new \Exception("Method {$action} not found in controller {$controllerClass}");
                    }

                    $response = call_user_func_array([$controllerInstance, $action], $matches);

                    // Execute AFTER middleware/filters
                    $this->executeAfterMiddleware($route, $response);

                    return;
                }
            }

            throw new NotFoundException("Route not found: {$method} {$uri}");

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Execute before middleware
     */
    private function executeBeforeMiddleware(array $route)
    {
        // Execute middleware classes
        foreach ($route['middleware'] as $middlewareName) {
            if (isset($this->middlewareRegistry[$middlewareName])) {
                $middlewareClass = $this->middlewareRegistry[$middlewareName];
                $middleware = new $middlewareClass();

                $result = $middleware->before();
                if ($result !== null) {
                    return $result; // Halt execution
                }
            }
        }

        // Execute before filters (callables)
        foreach ($route['before'] as $filter) {
            if (is_callable($filter)) {
                $result = $filter();
                if ($result !== null) {
                    return $result; // Halt execution
                }
            }
        }

        return null; // Continue
    }

    /**
     * Execute after middleware
     */
    private function executeAfterMiddleware(array $route, $response): void
    {
        // Execute after filters (callables)
        foreach ($route['after'] as $filter) {
            if (is_callable($filter)) {
                $response = $filter($response);
            }
        }

        // Execute middleware classes (in reverse order)
        foreach (array_reverse($route['middleware']) as $middlewareName) {
            if (isset($this->middlewareRegistry[$middlewareName])) {
                $middlewareClass = $this->middlewareRegistry[$middlewareName];
                $middleware = new $middlewareClass();

                $response = $middleware->after($response);
            }
        }
    }

    public function getGroupedRoutes(): array
    {
        $grouped = [];
        $ungrouped = [];

        foreach ($this->routes as $route) {
            if (!$route['visible'])
                continue;

            if (empty($route['tags'])) {
                $ungrouped[] = $route;
            } else {
                foreach ($route['tags'] as $tag) {
                    if (!isset($grouped[$tag])) {
                        $grouped[$tag] = [];
                    }
                    $grouped[$tag][] = $route;
                }
            }
        }

        ksort($grouped);
        if (!empty($ungrouped)) {
            $grouped['Other'] = $ungrouped;
        }

        return $grouped;
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    private function patternToRegex($pattern): string
    {
        if (str_ends_with($pattern, '*')) {
            $pattern = rtrim($pattern, '*') . '.*?';
        } else {
            $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', $pattern);
        }
        return '#^' . $pattern . '$#';
    }

    private function calculateSpecificity($pattern): int
    {
        $score = 0;
        $segments = explode('/', trim($pattern, '/'));

        foreach ($segments as $segment) {
            if ($segment === '*') {
                $score += 10;
            } elseif (preg_match('/\{[^}]+\}/', $segment)) {
                $score += 1;
            } else {
                $score += 5;
            }
        }

        return $score;
    }
}
