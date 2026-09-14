<?php
/**
 * 会话管理
 */

declare(strict_types=1);

namespace App\Core;

class Session
{
    /**
     * 启动会话（幂等）
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = (int) config('session.lifetime', 7200);

        // 指定会话文件目录，避免依赖 php.ini 的默认路径
        $savePath = (string) config('session.save_path', '');
        if ($savePath !== '') {
            if (!is_dir($savePath)) {
                @mkdir($savePath, 0775, true);
            }
            if (is_dir($savePath) && is_writable($savePath)) {
                session_save_path($savePath);
            }
        }

        session_name((string) config('session.name', 'dramatool_sid'));
        session_set_cookie_params([
            'lifetime' => 0,          // 浏览器会话 Cookie，关闭即失效
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        session_start();

        // 空闲超时控制
        $now = time();
        if (isset($_SESSION['_last_active']) && ($now - (int) $_SESSION['_last_active']) > $lifetime) {
            self::destroy();
            session_start();
        }
        $_SESSION['_last_active'] = $now;
    }

    /**
     * 读取会话值
     */
    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * 写入会话值
     */
    public static function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * 删除会话值
     */
    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * 判断键是否存在
     */
    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * 写入一次性提示消息
     */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /**
     * 取出并清空全部提示消息
     */
    public static function takeFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    /**
     * 保存表单回填数据（仅保留一次请求）
     */
    public static function flashInput(array $input): void
    {
        unset($input['password'], $input['password_confirm'], $input['_token']);
        $_SESSION['_old'] = $input;
    }

    /**
     * 清空表单回填数据
     */
    public static function clearOldInput(): void
    {
        unset($_SESSION['_old']);
    }

    /**
     * 销毁会话
     */
    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    /**
     * 登录后重新生成会话 ID，防止会话固定攻击
     *
     * 会话数据保留，但 CSRF 令牌必须轮换：旧令牌随旧会话 ID 一起失效，
     * 避免会话固定场景下攻击者预先获知的令牌继续可用。
     */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
        unset($_SESSION['_csrf_token']);
    }
}
