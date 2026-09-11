<?php
/**
 * 首页
 * 展示项目主背景、标题与"开始使用"按钮，点击后跳转至编辑器页面。
 * 路由：/
 */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asha · 文字冒险游戏在线编辑器</title>
    <meta name="description" content="在浏览器中编写剧本、生成场景、即时预览的文字冒险游戏在线编辑器。">
    <link rel="stylesheet" href="assets/css/home.css">
</head>
<body>
    <!-- 背景层 -->
    <div class="bg-layer" aria-hidden="true">
        <div class="bg-grid"></div>
        <div class="bg-orb bg-orb--1"></div>
        <div class="bg-orb bg-orb--2"></div>
        <div class="bg-orb bg-orb--3"></div>
    </div>

    <!-- 主内容 -->
    <main class="hero">
        <p class="hero__brand">Asha</p>
        <h1 class="hero__title">让故事，成为唯一的主角</h1>
        <p class="hero__subtitle">
            在浏览器中编写剧本，调用内置素材生成场景，<br>
            一键导出配置文件，即刻预览你的文字冒险游戏。
        </p>

        <div class="hero__actions">
            <button type="button" class="btn btn--primary" id="btnStart">
                开始使用
            </button>
            <a class="btn btn--ghost is-disabled" aria-disabled="true" title="二期上线">
                登录 / 注册
            </a>
        </div>

        <nav class="hero__links">
            <a href="works.php" class="link is-disabled" aria-disabled="true" title="三期上线">作品广场</a>
            <span class="dot">·</span>
            <a href="#" class="link is-disabled" aria-disabled="true" title="即将上线">使用文档</a>
            <span class="dot">·</span>
            <a href="#" class="link is-disabled" aria-disabled="true" title="即将上线">关于项目</a>
        </nav>
    </main>

    <!-- 页脚 -->
    <footer class="footer">
        <div class="footer__inner">
            <span>© 2026 Asha AVG Editor</span>
            <span class="dot">·</span>
            <span>备案号：待填写</span>
            <span class="dot">·</span>
            <a href="mailto:contact@example.com" class="link">联系我们</a>
        </div>
    </footer>

    <script src="assets/js/home.js"></script>
</body>
</html>
