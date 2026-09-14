<?php
/**
 * 登录后页面公共头部
 *
 * @var array|null $user  当前登录用户
 * @var string     $title 页面标题
 * @var string     $active 当前导航项：works / profile
 */
$title = $title ?? 'Dramatool';
$active = $active ?? '';
$displayName = $user ? ($user['nickname'] !== '' ? $user['nickname'] : $user['email']) : '';

// 分享元信息（详情页传入，用于社交平台卡片展示）
$ogTitle = $ogTitle ?? '';
$ogDesc = $ogDesc ?? '';
$ogUrl = $ogUrl ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> · Dramatool</title>
<?php if ($ogTitle !== ''): ?>
    <meta name="description" content="<?= e($ogDesc) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Dramatool">
    <meta property="og:title" content="<?= e($ogTitle) ?>">
    <meta property="og:description" content="<?= e($ogDesc) ?>">
    <meta property="og:url" content="<?= e($ogUrl) ?>">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= e($ogTitle) ?>">
    <meta name="twitter:description" content="<?= e($ogDesc) ?>">
<?php endif; ?>
    <script><?= \App\Core\Theme::foucScript() ?></script>
    <link rel="stylesheet" href="<?= e(asset('assets/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/home.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/auth.css')) ?>">
    <script>
        // 服务端注入的运行时上下文（供前端读取 CSRF 令牌等）
        window.DRAMATOOL_CTX = <?= json_encode([
            'baseUrl'   => base_url('/'),
            'csrfToken' => \App\Core\Csrf::token(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
</head>
<body class="app-body">
    <div class="bg-layer" aria-hidden="true">
        <div class="bg-grid"></div>
        <div class="bg-orb bg-orb--1"></div>
        <div class="bg-orb bg-orb--2"></div>
    </div>

    <header class="appbar">
        <div class="appbar__inner">
            <a class="appbar__brand" href="<?= e(base_url('/')) ?>">Dramatool</a>
            <nav class="appbar__nav">
                <a class="appbar__link<?= $active === 'works' ? ' is-active' : '' ?>"
                   href="<?= e(base_url('works')) ?>">我的作品</a>
                <a class="appbar__link<?= $active === 'square' ? ' is-active' : '' ?>"
                   href="<?= e(base_url('square')) ?>">作品广场</a>
                <a class="appbar__link<?= $active === 'favorites' ? ' is-active' : '' ?>"
                   href="<?= e(base_url('favorites')) ?>">我的收藏</a>
                <a class="appbar__link<?= $active === 'profile' ? ' is-active' : '' ?>"
                   href="<?= e(base_url('profile')) ?>">个人中心</a>
<?php if ($user !== null && ($user['role'] ?? '') === 'admin'): ?>
                <a class="appbar__link<?= $active === 'admin' ? ' is-active' : '' ?>"
                   href="<?= e(base_url('admin')) ?>">管理后台</a>
<?php endif; ?>
            </nav>
            <div class="appbar__right">
                <button id="themeToggle" class="btn btn--ghost btn--sm" type="button"
                        title="切换主题（深色 / 浅色 / 护眼 / 跟随系统）">主题</button>
                <a class="btn btn--primary btn--sm" href="<?= e(base_url('editor')) ?>">开始创作</a>
                <span class="appbar__user"><?= e($displayName) ?></span>
                <form method="post" action="<?= e(base_url('logout')) ?>" class="inline-form">
                    <?= \App\Core\Csrf::field() ?>
                    <button class="btn btn--ghost btn--sm" type="submit">退出</button>
                </form>
            </div>
        </div>
    </header>

    <main class="app-main">
