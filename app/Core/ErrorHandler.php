<?php
/**
 * 全局错误与异常处理
 */

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

class ErrorHandler
{
    private static bool $debug = false;

    /**
     * 注册处理器
     */
    public static function register(bool $debug): void
    {
        self::$debug = $debug;

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            self::render($e);
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::render(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
            }
        });
    }

    /**
     * 输出错误响应
     */
    private static function render(Throwable $e): void
    {
        self::log($e);

        if (!headers_sent()) {
            http_response_code(500);
        }

        $wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
            || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

        if ($wantsJson) {
            $payload = ['ok' => false, 'message' => self::$debug ? $e->getMessage() : '服务器内部错误'];
            if (self::$debug) {
                $payload['trace'] = explode("\n", $e->getTraceAsString());
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            return;
        }

        if (self::$debug) {
            echo '<h1>服务器错误</h1>';
            echo '<p><strong>' . e($e->getMessage()) . '</strong></p>';
            echo '<p>' . e($e->getFile()) . ':' . $e->getLine() . '</p>';
            echo '<pre>' . e($e->getTraceAsString()) . '</pre>';
        } elseif (headers_sent()) {
            // 已有内容输出，无法再渲染完整页面
            echo '<h1>500</h1><p>服务器内部错误，请稍后重试。</p>';
        } else {
            View::errorPage(500);
        }
    }

    /**
     * 写入错误日志
     */
    private static function log(Throwable $e): void
    {
        $dir = (string) config('paths.storage') . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = sprintf(
            "[%s] %s: %s in %s:%d\n%s\n\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        @file_put_contents($dir . '/error-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
