<?php
/**
 * 视图渲染
 */

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

class View
{
    /**
     * 渲染视图文件
     *
     * @param string $view 相对 app/Views 的路径，如 'home/index'
     * @param array  $data 传入视图的变量
     */
    public static function render(string $view, array $data = []): void
    {
        $file = rtrim((string) config('paths.views'), '/') . '/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('视图文件不存在：' . $file);
        }

        extract($data, EXTR_SKIP);
        require $file;
    }

    /**
     * 渲染统一错误页
     *
     * 视图渲染本身失败时退化为最简 HTML，保证始终有响应输出。
     *
     * @param int    $code   HTTP 状态码
     * @param string $detail 调试信息（仅 debug 模式展示）
     */
    public static function errorPage(int $code, string $detail = ''): void
    {
        if (!headers_sent()) {
            http_response_code($code);
        }

        // JSON / AJAX 请求返回 JSON 而非 HTML
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        if (stripos($accept, 'application/json') !== false
            || strtolower($requestedWith) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'      => false,
                'code'    => $code,
                'message' => $detail !== '' && config('app.debug') ? $detail : '请求失败',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            self::render('errors.error', [
                'code'   => $code,
                'detail' => $detail,
            ]);
        } catch (\Throwable $e) {
            echo '<!DOCTYPE html><meta charset="UTF-8"><h1>' . $code . '</h1>';
            if (config('app.debug') && $detail !== '') {
                echo '<p>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</p>';
            }
        }
    }
}
