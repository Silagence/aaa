<?php
/**
 * 统一错误页（404 / 405 / 419 / 500 等）
 *
 * 自包含页面，复用主题变量与首页背景，不依赖登录态与导航。
 *
 * @var int    $code   HTTP 状态码
 * @var string $detail 调试信息（仅 debug 模式下传入并展示）
 */

$titles = [
    400 => ['请求出错', '服务器无法理解这次请求，请检查后重试。'],
    403 => ['无权访问', '你没有权限访问这个页面。'],
    404 => ['页面走丢了', '你访问的页面不存在，或已被移动。'],
    405 => ['请求方式不支持', '当前请求方法不被该页面接受。'],
    419 => ['页面已过期', '为了安全，表单凭据已失效，请刷新页面后重试。'],
    429 => ['操作过于频繁', '请求过于频繁，请稍后再试。'],
    500 => ['服务器开小差了', '服务器内部出错，请稍后重试。'],
    503 => ['服务暂不可用', '系统正在维护中，请稍后再访问。'],
];
[$title, $desc] = $titles[$code] ?? ['出错了', '请求处理过程中发生错误，请稍后重试。'];

$showDetail = !empty($detail) && (bool) config('app.debug');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $code ?> · <?= e($title) ?> · Dramatool</title>
    <script><?= \App\Core\Theme::foucScript() ?></script>
    <link rel="stylesheet" href="<?= e(asset('assets/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/home.css')) ?>">
</head>
<body>
    <div class="bg-layer" aria-hidden="true">
        <div class="bg-grid"></div>
        <div class="bg-orb bg-orb--1"></div>
        <div class="bg-orb bg-orb--2"></div>
        <div class="bg-orb bg-orb--3"></div>
    </div>

    <main class="error-page">
        <p class="error-page__code"><?= (int) $code ?></p>
        <h1 class="error-page__title"><?= e($title) ?></h1>
        <p class="error-page__desc"><?= e($desc) ?></p>

        <?php if ($showDetail): ?>
            <pre class="error-page__detail"><?= e($detail) ?></pre>
        <?php endif; ?>

        <div class="error-page__actions">
            <?php if ($code === 419): ?>
                <button class="btn btn--primary" type="button" onclick="location.reload()">刷新重试</button>
                <a class="btn btn--ghost" href="<?= e(base_url('/')) ?>">返回首页</a>
            <?php else: ?>
                <a class="btn btn--primary" href="<?= e(base_url('/')) ?>">返回首页</a>
                <a class="btn btn--ghost" href="<?= e(base_url('square')) ?>">去作品广场</a>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
