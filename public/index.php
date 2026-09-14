<?php
/**
 * 统一入口（front controller）
 *
 * 所有请求经由此文件进入应用：加载引导 → 注册路由 → 分发。
 * 静态资源（assets/ 下的文件）由 Web 服务器直接返回，不会走到这里。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

/** @var App\Core\Router $router */
$router = require DRAMATOOL_ROOT . '/app/routes.php';

$router->dispatch();
