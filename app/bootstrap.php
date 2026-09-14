<?php
/**
 * 应用引导文件
 *
 * 负责：加载配置、注册自动加载、初始化时区与错误处理、启动会话。
 * 由 public/index.php 引入，是唯一的启动入口。
 */

declare(strict_types=1);

// 项目根目录
define('DRAMATOOL_ROOT', dirname(__DIR__));

// 加载配置
$GLOBALS['config'] = require DRAMATOOL_ROOT . '/config/config.php';
$config = $GLOBALS['config'];

// 时区
date_default_timezone_set($config['app']['timezone']);

// 错误显示策略：开发环境显示，生产环境记录
if ($config['app']['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}

// 自动加载：App\Foo\Bar → app/Foo/Bar.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = DRAMATOOL_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// 全局辅助函数
require DRAMATOOL_ROOT . '/app/helpers.php';

// 启动会话
App\Core\Session::start();

// 注册错误/异常处理器
App\Core\ErrorHandler::register($config['app']['debug']);
