<?php
/**
 * CSRF 令牌管理
 */

declare(strict_types=1);

namespace App\Core;

class Csrf
{
    private const KEY = '_csrf_token';

    /**
     * 获取当前令牌，不存在则生成
     */
    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    /**
     * 生成隐藏域 HTML
     */
    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    /**
     * 校验令牌
     */
    public static function verify(?string $token): bool
    {
        $expected = $_SESSION[self::KEY] ?? '';
        if ($expected === '' || $token === null || $token === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    /**
     * 校验请求中的令牌，失败则终止
     */
    public static function check(): void
    {
        $token = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!self::verify(is_string($token) ? $token : null)) {
            http_response_code(419);
            if (self::wantsJson()) {
                json_response(['ok' => false, 'message' => '页面已过期，请刷新后重试'], 419);
            }
            exit('页面已过期，请刷新后重试');
        }
    }

    /**
     * 判断当前请求是否期望 JSON 响应
     */
    private static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return stripos($accept, 'application/json') !== false
            || strtolower($requestedWith) === 'xmlhttprequest';
    }
}
