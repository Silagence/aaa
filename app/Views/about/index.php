<?php
/**
 * 关于网站
 *
 * 面向所有访客的公开页面，介绍 Dramatool 的定位、核心能力与联系方式。
 *
 * @var array|null $user  当前登录用户
 * @var array      $flash 一次性提示消息
 */
$title = '关于网站';
$active = 'about';
require __DIR__ . '/../partials/app_head.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">关于网站</h1>
        <p class="page-desc">Dramatool —— 让故事，成为唯一的主角。</p>
    </div>
</div>

<?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>

<div class="doc doc--no-toc">
    <div class="doc__body">
        <section class="doc-section">
            <h2 class="doc-section__title">我们是什么</h2>
            <p>
                Dramatool 是一款运行在浏览器中的文字冒险游戏（AVG）在线编辑器。
                无需安装任何软件，你就能编写剧本、调用内置素材生成场景、即时预览，
                并一键导出配置文件，把脑海中的故事变成可以玩的游戏。
            </p>
        </section>

        <section class="doc-section">
            <h2 class="doc-section__title">核心能力</h2>
            <div class="feature-grid">
                <div class="feature-card">
                    <h3 class="feature-card__title">文档式剧本书写</h3>
                    <p class="feature-card__desc">以纯文本与快捷语法书写剧本，输入即创作，专注故事本身。</p>
                </div>
                <div class="feature-card">
                    <h3 class="feature-card__title">内置素材库</h3>
                    <p class="feature-card__desc">背景、立绘、音乐、音效一应俱全，也支持上传自己的素材。</p>
                </div>
                <div class="feature-card">
                    <h3 class="feature-card__title">实时预览</h3>
                    <p class="feature-card__desc">边写边玩，随时在播放器中检查分支走向与演出效果。</p>
                </div>
                <div class="feature-card">
                    <h3 class="feature-card__title">一键导出</h3>
                    <p class="feature-card__desc">导出配置文件，便于备份、迁移与二次分发。</p>
                </div>
                <div class="feature-card">
                    <h3 class="feature-card__title">作品广场</h3>
                    <p class="feature-card__desc">发布作品，让其他玩家试玩、点赞、收藏与评论。</p>
                </div>
                <div class="feature-card">
                    <h3 class="feature-card__title">历史版本</h3>
                    <p class="feature-card__desc">每次保存都会留下版本记录，随时回滚到任意一次修改。</p>
                </div>
            </div>
        </section>

        <section class="doc-section">
            <h2 class="doc-section__title">技术说明</h2>
            <ul class="doc-list">
                <li>基于 PHP 构建的服务端，配合原生 JavaScript 实现编辑器与播放器。</li>
                <li>支持深色 / 浅色 / 护眼 / 跟随系统四种主题，全站统一。</li>
                <li>作品支持独立分享链接与 iframe 嵌入，方便分发。</li>
            </ul>
        </section>

        <section class="doc-section">
            <h2 class="doc-section__title">联系我们</h2>
            <p>如果你在使用中遇到问题，或有功能建议与合作意向，欢迎通过以下方式联系我们：</p>
            <ul class="doc-list">
                <li>邮箱：<a class="link" href="mailto:contact@example.com">contact@example.com</a></li>
                <li>也可以先阅读 <a class="link" href="<?= e(base_url('manual')) ?>">使用手册</a>，了解常见问题的解决办法。</li>
            </ul>
        </section>

        <div class="doc__actions">
            <a class="btn btn--primary" href="<?= e(base_url('editor')) ?>">开始创作</a>
            <a class="btn btn--ghost" href="<?= e(base_url('manual')) ?>">查看使用手册</a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../partials/app_foot.php'; ?>
