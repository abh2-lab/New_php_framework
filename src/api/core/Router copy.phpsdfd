<?php

namespace App\Core;

use App\Core\Exceptions\NotFoundException;

class Router
{
    private $routes = [];
    public function __construct(private $basePath = '')
    {
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Enhanced add method - only array syntax with best key names
     */
    public function add(array $config)
    {
        // Extract with defaults and best naming
        $method = strtoupper($config['method'] ?? 'GET');
        $pattern = $config['url'] ?? $config['pattern'] ?? '/';
        $handler = $config['controller'] ?? $config['handler'] ?? '';
        $description = $config['desc'] ?? $config['description'] ?? '';
        $visible = $config['visible'] ?? true;
        $showHeaders = $config['showHeaders'] ?? false;
        $tags = $config['group'] ?? $config['tags'] ?? [];

        // Enhanced parameter handling with types
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
            'tags' => is_array($tags) ? $tags : [$tags],
            'params' => [
                'url' => $urlParams,
                'get' => $getParams,
                'form' => $formParams,
                'json' => $jsonParams
            ],
            'regex' => $this->patternToRegex($pattern),
            'specificity' => $this->calculateSpecificity($pattern)
        ];

        $this->routes[] = $route;
        usort($this->routes, fn($a, $b) => $b['specificity'] <=> $a['specificity']);
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

    public function dispatch_old()
    {
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $uri = '/' . trim(str_replace($this->basePath, '', $uri), '/');

            foreach ($this->routes as $route) {
                if ($method !== $route['method'])
                    continue;

                if (preg_match($route['regex'], $uri, $matches)) {
                    array_shift($matches);
                    [$controller, $action] = explode('@', $route['handler']);

                    $controllerClass = "App\\Controllers\\$controller";

                    if (!class_exists($controllerClass)) {
                        throw new \Exception("Controller not found: $controllerClass");
                    }

                    $controllerInstance = new $controllerClass();

                    // Set router for DocsController
                    if ($controller === 'DocsController') {
                        $controllerInstance->setRouter($this);
                    }

                    if (!method_exists($controllerInstance, $action)) {
                        throw new \Exception("Method '$action' not found in controller '$controllerClass'");
                    }

                    call_user_func_array([$controllerInstance, $action], $matches);
                    return;
                }
            }

            throw new NotFoundException("Route not found: $method $uri");

        } catch (\Throwable $e) {
            throw $e;
        }
    }



    public function dispatch()
    {
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

            // Remove base path properly (only from the beginning)
            if (!empty($this->basePath)) {
                $basePath = '/' . trim($this->basePath, '/');
                // Only remove if URI starts with base path
                if (strpos($uri, $basePath) === 0) {
                    $uri = substr($uri, strlen($basePath));
                }
            }

            // Ensure URI starts with / and normalize
            $uri = '/' . trim($uri, '/');

            // Handle empty URI (root)
            if ($uri === '/') {
                $uri = '/';
            }

            foreach ($this->routes as $route) {
                if ($method !== $route['method'])
                    continue;

                if (preg_match($route['regex'], $uri, $matches)) {
                    array_shift($matches);
                    [$controller, $action] = explode('@', $route['handler']);

                    $controllerClass = "App\\Controllers\\$controller";

                    if (!class_exists($controllerClass)) {
                        throw new \Exception("Controller not found: $controllerClass");
                    }

                    $controllerInstance = new $controllerClass();

                    // Set router for DocsController
                    if ($controller === 'DocsController') {
                        $controllerInstance->setRouter($this);
                    }

                    if (!method_exists($controllerInstance, $action)) {
                        throw new \Exception("Method '$action' not found in controller '$controllerClass'");
                    }

                    call_user_func_array([$controllerInstance, $action], $matches);
                    return;
                }
            }

            throw new NotFoundException("Route not found: $method $uri");

        } catch (\Throwable $e) {
            throw $e;
        }
    }


    private function patternToRegex($pattern)
    {
        if (str_ends_with($pattern, '/*')) {
            $pattern = rtrim($pattern, '/*') . '(/.*)?';
        } else {
            $pattern = preg_replace('#\{([\w]+)\}#', '([^/]+)', $pattern);
        }
        return '#^' . $pattern . '$#';
    }

    private function calculateSpecificity($pattern)
    {
        $score = 0;
        $segments = explode('/', trim($pattern, '/'));

        foreach ($segments as $segment) {
            if ($segment === '*') {
                $score -= 10;
            } elseif (preg_match('/^\{.+\}$/', $segment)) {
                $score += 1;
            } else {
                $score += 5;
            }
        }

        return $score;
    }
}
