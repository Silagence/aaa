<?php
/**
 * 网站公告（需登录）
 *
 * 展示管理员已发布的公告，置顶公告排在最前。
 *
 * @var array $user          当前登录用户
 * @var array $flash         一次性提示消息
 * @var array $announcements 已发布公告列表
 */
$title = '网站公告';
$active = 'announcements';
require __DIR__ . '/../partials/app_head.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">网站公告</h1>
        <p class="page-desc">共 <?= count($announcements) ?> 条公告</p>
    </div>
</div>

<?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>

<?php if (!$announcements): ?>
    <div class="empty">
        <p class="empty__title">暂无公告</p>
        <p class="empty__desc">管理员还没有发布任何公告，请稍后再来看看。</p>
    </div>
<?php else: ?>
    <div class="announcement-list">
        <?php foreach ($announcements as $item): ?>
            <?php $isPinned = (int) $item['pinned'] === 1; ?>
            <article class="announcement-item<?= $isPinned ? ' announcement-item--pinned' : '' ?>">
                <header class="announcement-item__head">
                    <h2 class="announcement-item__title">
                        <?php if ($isPinned): ?>
                            <span class="badge badge--accent">置顶</span>
                        <?php endif; ?>
                        <?= e((string) $item['title']) ?>
                    </h2>
                    <time class="announcement-item__time"><?= e((string) $item['created_at']) ?></time>
                </header>
                <div class="announcement-item__content"><?= nl2br(e((string) $item['content'])) ?></div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../partials/app_foot.php'; ?>
