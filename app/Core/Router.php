<?php
/**
 * 路由注册与分发
 *
 * 支持 GET / POST 注册，路径参数以 {name} 占位。
 */

declare(strict_types=1);

namespace App\Core;

class Router
{
    /** @var array<string, array<string, array>> */
    private array $routes = [
        'GET'  => [],
        'POST' => [],
    ];

    public function get(string $path, $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    private function add(string $method, string $path, $handler): void
    {
        $this->routes[$method][$this->normalize($path)] = $handler;
    }

    /**
     * 归一化路径：去掉首尾斜杠
     */
    private function normalize(string $path): string
    {
        return trim($path, '/');
    }

    /**
     * 分发当前请求
     */
    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = $this->normalize(rawurldecode($uri));

        // 兼容部分环境未开启 rewrite 时通过 ?_url= 传参
        if ($path === '' && isset($_GET['_url'])) {
            $path = $this->normalize((string) $_GET['_url']);
        }

        if (!isset($this->routes[$method])) {
            $this->abort(405, '不支持的请求方法');
            return;
        }

        // 先精确匹配
        if (isset($this->routes[$method][$path])) {
            $this->invoke($this->routes[$method][$path], []);
            return;
        }

        // 再按占位符匹配
        foreach ($this->routes[$method] as $route => $handler) {
            if (strpos($route, '{') === false) {
                continue;
            }
            $quoted = preg_quote($route, '#');
            // {name} 默认匹配单段；{name:path} 匹配含斜杠的多段路径
            // 注意：preg_quote 会把冒号转义为 \:，因此模式中需写成 \:?
            $pattern = preg_replace('#\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}#', '(?P<$1>[^/]+)', $quoted);
            $pattern = preg_replace('#\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\:path\\\\\}#', '(?P<$1>.+)', $pattern);
            $pattern = '#^' . $pattern . '$#';
            if (preg_match($pattern, $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $this->invoke($handler, $params);
                return;
            }
        }

        $this->abort(404, '页面不存在');
    }

    /**
     * 执行处理器
     */
    private function invoke($handler, array $params): void
    {
        if (is_callable($handler)) {
            call_user_func_array($handler, $params);
            return;
        }

        if (is_string($handler) && strpos($handler, '@') !== false) {
            [$class, $action] = explode('@', $handler, 2);
            $class = 'App\\Controllers\\' . $class;
            if (!class_exists($class)) {
                $this->abort(500, '控制器不存在：' . $class);
                return;
            }
            $controller = new $class();
            if (!method_exists($controller, $action)) {
                $this->abort(500, '方法不存在：' . $class . '::' . $action);
                return;
            }
            call_user_func_array([$controller, $action], $params);
            return;
        }

        $this->abort(500, '路由处理器无效');
    }

    /**
     * 输出错误页并终止
     */
    private function abort(int $status, string $message): void
    {
        View::errorPage($status, config('app.debug') ? $message : '');
        exit;
    }
}
