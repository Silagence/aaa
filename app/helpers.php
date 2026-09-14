<?php
/**
 * 全局辅助函数
 */

declare(strict_types=1);

if (!function_exists('config')) {
    /**
     * 读取配置，支持点号路径：config('db.host')
     */
    function config(?string $key = null, $default = null)
    {
        $all = $GLOBALS['config'] ?? [];
        if ($key === null) {
            return $all;
        }
        $value = $all;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}

if (!function_exists('e')) {
    /**
     * HTML 转义输出，防止 XSS
     */
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('base_url')) {
    /**
     * 生成站内绝对路径
     */
    function base_url(string $path = ''): string
    {
        $base = rtrim((string) config('app.url', ''), '/');
        if ($base === '') {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $scheme = $https ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base = $scheme . '://' . $host;
        }
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /**
     * 生成静态资源 URL，并附加修改时间戳用于缓存失效
     */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = config('paths.public') . '/' . $path;
        $version = is_file($file) ? '?v=' . filemtime($file) : '';
        return base_url($path) . $version;
    }
}

if (!function_exists('json_response')) {
    /**
     * 输出 JSON 响应并终止
     */
    function json_response($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('old')) {
    /**
     * 读取一次性表单回填数据
     */
    function old(string $key, string $default = ''): string
    {
        $flash = App\Core\Session::get('_old', []);
        return (string) ($flash[$key] ?? $default);
    }
}
