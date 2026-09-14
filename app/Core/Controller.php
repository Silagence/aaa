<?php
/**
 * 控制器基类
 */

declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    /**
     * 渲染视图
     */
    protected function view(string $view, array $data = []): void
    {
        View::render($view, $data);
    }

    /**
     * 输出 JSON
     */
    protected function json($data, int $status = 200): void
    {
        json_response($data, $status);
    }

    /**
     * 跳转
     */
    protected function redirect(string $path, int $status = 302): void
    {
        header('Location: ' . base_url($path), true, $status);
        exit;
    }

    /**
     * 读取 GET 参数
     */
    protected function query(string $key, $default = null)
    {
        $value = $_GET[$key] ?? $default;
        return is_string($value) ? trim($value) : $value;
    }

    /**
     * 读取 POST 参数
     */
    protected function input(string $key, $default = null)
    {
        $value = $_POST[$key] ?? $default;
        return is_string($value) ? trim($value) : $value;
    }

    /**
     * 判断是否为 AJAX / JSON 请求
     */
    protected function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return stripos($accept, 'application/json') !== false
            || strtolower($requestedWith) === 'xmlhttprequest';
    }

    /**
     * 校验 CSRF（写操作必须调用）
     *
     * 未通过时返回 JSON 错误（AJAX 请求）或 419 页面，并终止后续逻辑。
     */
    protected function verifyCsrf(): void
    {
        if (Csrf::verify($this->csrfToken())) {
            return;
        }

        if ($this->wantsJson()) {
            $this->json(['ok' => false, 'message' => '页面已过期，请刷新后重试'], 419);
        }
        View::errorPage(419);
        exit;
    }

    /**
     * 读取请求携带的 CSRF 令牌（表单字段或请求头）
     */
    private function csrfToken(): ?string
    {
        $token = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        return is_string($token) ? $token : null;
    }
}
