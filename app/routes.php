<?php
/**
 * 路由定义
 *
 * 返回一个已注册路由的 Router 实例。
 * 路径不带 .php 后缀，同时通过 public/.htaccess 与兼容规则保留旧 URL 可用。
 */

declare(strict_types=1);

use App\Core\Router;

/** @var Router $router */
$router = new Router();

// ===== 页面路由 =====
$router->get('/', 'HomeController@index');
$router->get('index.php', 'HomeController@index');
$router->get('editor', 'EditorController@index');
$router->get('editor.php', 'EditorController@index');
$router->get('player', 'PlayerController@index');
$router->get('player.php', 'PlayerController@index');

// ===== 认证 =====
$router->get('login', 'AuthController@showLogin');
$router->post('login', 'AuthController@login');
$router->get('register', 'AuthController@showRegister');
$router->post('register', 'AuthController@register');
$router->post('logout', 'AuthController@logout');
$router->get('password/forgot', 'AuthController@showForgot');
$router->post('password/forgot', 'AuthController@forgot');
$router->get('password/reset', 'AuthController@showReset');
$router->post('password/reset', 'AuthController@reset');

// ===== 个人中心 =====
$router->get('profile', 'ProfileController@index');
$router->post('profile', 'ProfileController@update');
$router->post('profile/password', 'ProfileController@password');
$router->post('profile/avatar', 'ProfileController@avatar');
$router->post('profile/avatar/delete', 'ProfileController@avatarDelete');
$router->post('profile/delete', 'ProfileController@destroy');

// ===== 作品 =====
$router->get('works', 'WorkController@index');
$router->get('square', 'WorkController@square');
$router->get('favorites', 'WorkController@favorites');

// 作品 JSON 接口（供编辑器调用）
$router->post('api/works/save', 'WorkController@save');
$router->get('api/works/{id}/public', 'WorkController@showPublic');
$router->get('api/works/{id}', 'WorkController@show');
$router->post('api/works/{id}/delete', 'WorkController@delete');
$router->post('api/works/{id}/rename', 'WorkController@rename');
$router->post('api/works/{id}/publish', 'WorkController@publish');
$router->post('api/works/{id}/like', 'WorkController@like');
$router->post('api/works/{id}/favorite', 'WorkController@favorite');
$router->post('api/works/{id}/play', 'WorkController@play');
$router->get('api/works/{id}/revisions', 'WorkController@revisions');
$router->post('api/works/{id}/revisions/{revisionId}/restore', 'WorkController@restoreRevision');

// 评论（列表无需登录，发表/删除需登录）
$router->get('api/works/{id}/comments', 'WorkController@comments');
$router->post('api/works/{id}/comments', 'WorkController@commentStore');
$router->post('api/comments/{id}/delete', 'WorkController@commentDelete');

// 举报（需登录，作品与评论共用同一入口）
$router->post('api/reports', 'WorkController@report');

// ===== 管理后台（仅 role = admin 可访问）=====
$router->get('admin', 'AdminController@index');
$router->get('admin/reports', 'AdminController@reports');
$router->post('admin/reports/{id}/handle', 'AdminController@handleReport');
$router->get('admin/works', 'AdminController@works');
$router->post('admin/works/{id}/unpublish', 'AdminController@unpublishWork');
$router->get('admin/users', 'AdminController@users');
$router->post('admin/users/{id}/toggle', 'AdminController@toggleUser');

// ===== 用户素材（上传 / 列表 / 删除 / 元信息）=====
$router->get('api/assets', 'AssetController@index');
$router->post('api/assets', 'AssetController@store');
$router->post('api/assets/{id}/delete', 'AssetController@delete');
$router->post('api/assets/{id}/meta', 'AssetController@meta');

// 本地开发：PHP 内置服务器无法访问 public 之外的 storage/uploads，
// 由应用代为输出（生产环境由 Nginx 的 location /uploads/ 直接返回，不会走到这里）。
$router->get('uploads/{path:path}', 'AssetController@serve');

// ===== 分享（短链详情 / iframe 嵌入）=====
$router->get('w/{code}', 'WorkController@detail');
$router->get('embed/{code}', 'WorkController@embed');

return $router;
