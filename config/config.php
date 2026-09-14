<?php
/**
 * 全局配置
 *
 * 敏感信息（数据库账号密码）放在 config/local.php，该文件已被 .gitignore 忽略。
 * 首次部署时复制 config/local.php.example 为 config/local.php 并填写真实值。
 */

$local = [];
$localFile = __DIR__ . '/local.php';
if (is_file($localFile)) {
    $local = require $localFile;
}

return [
    // 应用信息
    'app' => [
        'name'     => 'Dramatool',
        'env'      => $local['app']['env'] ?? 'production',   // production / development
        'debug'    => $local['app']['debug'] ?? false,
        'url'      => $local['app']['url'] ?? '',             // 留空则自动推断
        'timezone' => 'Asia/Shanghai',
    ],

    // 数据库
    'db' => [
        'host'     => $local['db']['host'] ?? '127.0.0.1',
        'port'     => $local['db']['port'] ?? 3306,
        'database' => $local['db']['database'] ?? 'dramatool',
        'username' => $local['db']['username'] ?? 'root',
        'password' => $local['db']['password'] ?? '',
        'charset'  => 'utf8mb4',
    ],

    // 会话与安全
    'session' => [
        'name'      => 'dramatool_sid',
        'lifetime'  => 7200,       // 会话有效期（秒）
        'remember'  => 2592000,    // "记住我"有效期（秒，30 天）
        'save_path' => dirname(__DIR__) . '/storage/sessions',
    ],

    // 登录安全策略
    'auth' => [
        'max_fail'      => 5,      // 连续失败次数上限
        'lock_seconds'  => 900,    // 触发上限后锁定时长（秒）
        'password_min'  => 8,      // 密码最小长度

        // 注册限频（滑动窗口）
        'register_window'    => 3600,  // 统计窗口（秒）
        'register_ip_max'    => 5,     // 窗口内同一 IP 允许的注册尝试次数，0 表示不限制
        'register_email_max' => 3,     // 窗口内同一邮箱允许的注册尝试次数，0 表示不限制

        // 找回密码
        'reset_expire'       => 3600,  // 重置链接有效期（秒，1 小时）
        'reset_user_max'     => 3,     // 窗口内同一邮箱最多发送的重置邮件数，0 表示不限制
        'reset_ip_max'       => 10,    // 窗口内同一 IP 最多发送的重置邮件数，0 表示不限制
        'reset_window'       => 3600,  // 重置邮件限频窗口（秒）
    ],

    // 邮件发送
    'mail' => [
        // log：写入 storage/mail 日志（开发环境默认，无需 SMTP 服务）
        // smtp：通过配置的 SMTP 服务器真实发信
        'driver' => $local['mail']['driver'] ?? 'log',
        'from'   => [
            'address' => $local['mail']['from']['address'] ?? 'noreply@dramatool.local',
            'name'    => $local['mail']['from']['name'] ?? 'Dramatool',
        ],
        'smtp'   => [
            'host'       => $local['mail']['smtp']['host'] ?? '127.0.0.1',
            'port'       => (int) ($local['mail']['smtp']['port'] ?? 465),
            // ssl：直接 TLS（常见于 465 端口）；starttls：明文连接后升级（常见于 587）；none：不加密
            'encryption' => $local['mail']['smtp']['encryption'] ?? 'ssl',
            'username'   => $local['mail']['smtp']['username'] ?? '',
            'password'   => $local['mail']['smtp']['password'] ?? '',
            'timeout'    => (int) ($local['mail']['smtp']['timeout'] ?? 10),
        ],
    ],

    // 用户素材上传
    'upload' => [
        'max_size'    => 10 * 1024 * 1024,   // 单文件上限（字节）
        'quota_user'  => 200 * 1024 * 1024,  // 每用户总容量上限（字节）
        'quota_count' => 200,                // 每用户素材数量上限
        'dir'         => dirname(__DIR__) . '/storage/uploads',  // 物理存储根目录
        'url_prefix'  => 'uploads',          // 对外访问前缀（由 Nginx 映射到 storage/uploads）
        // 各类型允许的扩展名（不允许 svg，避免 XSS）
        'allowed'     => [
            'bg'     => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif'],
            'sprite' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif'],
            'bgm'    => ['mp3', 'ogg', 'wav', 'm4a', 'aac'],
            'sfx'    => ['mp3', 'ogg', 'wav', 'm4a', 'aac'],
        ],
        // 允许的 MIME 白名单（按类型）
        'mime'        => [
            'bg'     => ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'],
            'sprite' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'],
            'bgm'    => ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/aac', 'audio/x-m4a'],
            'sfx'    => ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/aac', 'audio/x-m4a'],
        ],
        // 版权协议选项（上传时由用户选择）
        'licenses'    => [
            'original'  => '原创作品，保留所有权利',
            'cc-by'     => 'CC BY 4.0（署名）',
            'cc-by-sa'  => 'CC BY-SA 4.0（署名-相同方式共享）',
            'cc0'       => 'CC0 1.0（公共领域贡献）',
        ],
    ],

    // 用户头像
    'avatar' => [
        'max_size' => 2 * 1024 * 1024,   // 单文件上限（字节）
        'size'     => 256,               // 统一缩放为 256×256 正方形
        'dir'      => dirname(__DIR__) . '/storage/uploads/avatars',  // 物理存储目录
        'url_prefix' => 'uploads/avatars',  // 对外访问前缀（由 Nginx 映射到 storage/uploads）
        // 允许的扩展名与 MIME（不允许 svg，避免 XSS）
        'allowed'  => ['png', 'jpg', 'jpeg', 'gif', 'webp'],
        'mime'     => ['image/png', 'image/jpeg', 'image/gif', 'image/webp'],
        // 版权协议选项，与素材上传共用同一套
        'licenses' => [
            'original' => '原创作品，保留所有权利',
            'cc-by'    => 'CC BY 4.0（署名）',
            'cc-by-sa' => 'CC BY-SA 4.0（署名-相同方式共享）',
            'cc0'      => 'CC0 1.0（公共领域贡献）',
        ],
    ],

    // 路径
    'paths' => [
        'root'    => dirname(__DIR__),
        'app'     => dirname(__DIR__) . '/app',
        'views'   => dirname(__DIR__) . '/app/Views',
        'storage' => dirname(__DIR__) . '/storage',
        'public'  => dirname(__DIR__) . '/public',
    ],
];
