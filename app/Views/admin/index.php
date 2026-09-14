<?php
/**
 * 管理后台 · 概览
 *
 * @var array $user  当前登录管理员
 * @var array $flash 一次性提示消息
 * @var array $stats 站点统计
 * @var array $latest 最新待处理举报
 */
$title = '管理后台';
$active = 'admin';
require __DIR__ . '/../partials/app_head.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">管理后台</h1>
        <p class="page-desc">处理内容举报、管理作品与用户</p>
    </div>
</div>

<?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>

<div class="admin-tabs">
    <a class="admin-tabs__item is-active" href="<?= e(base_url('admin')) ?>">概览</a>
    <a class="admin-tabs__item" href="<?= e(base_url('admin/reports')) ?>">
        举报处理<?php if ($stats['pending'] > 0): ?><span class="admin-tabs__badge"><?= (int) $stats['pending'] ?></span><?php endif; ?>
    </a>
    <a class="admin-tabs__item" href="<?= e(base_url('admin/works')) ?>">作品管理</a>
    <a class="admin-tabs__item" href="<?= e(base_url('admin/users')) ?>">用户管理</a>
    <a class="admin-tabs__item" href="<?= e(base_url('admin/announcements')) ?>">公告管理</a>
</div>

<div class="stat-grid">
    <a class="stat-card<?= $stats['pending'] > 0 ? ' stat-card--alert' : '' ?>"
       href="<?= e(base_url('admin/reports?status=0')) ?>">
        <span class="stat-card__label">待处理举报</span>
        <span class="stat-card__value"><?= (int) $stats['pending'] ?></span>
    </a>
    <a class="stat-card" href="<?= e(base_url('admin/reports?status=1')) ?>">
        <span class="stat-card__label">已处理举报</span>
        <span class="stat-card__value"><?= (int) $stats['resolved'] ?></span>
    </a>
    <a class="stat-card" href="<?= e(base_url('admin/reports?status=2')) ?>">
        <span class="stat-card__label">已驳回举报</span>
        <span class="stat-card__value"><?= (int) $stats['rejected'] ?></span>
    </a>
    <a class="stat-card" href="<?= e(base_url('admin/users')) ?>">
        <span class="stat-card__label">注册用户</span>
        <span class="stat-card__value"><?= (int) $stats['users'] ?></span>
        <?php if ($stats['users_disabled'] > 0): ?>
            <span class="stat-card__hint">其中 <?= (int) $stats['users_disabled'] ?> 个已禁用</span>
        <?php endif; ?>
    </a>
    <a class="stat-card" href="<?= e(base_url('admin/works')) ?>">
        <span class="stat-card__label">作品总数</span>
        <span class="stat-card__value"><?= (int) $stats['works'] ?></span>
        <span class="stat-card__hint">已发布 <?= (int) $stats['works_public'] ?></span>
    </a>
    <a class="stat-card" href="<?= e(base_url('admin/announcements')) ?>">
        <span class="stat-card__label">已发布公告</span>
        <span class="stat-card__value"><?= (int) $stats['announcements'] ?></span>
    </a>
</div>

<section class="admin-section">
    <div class="admin-section__head">
        <h2 class="admin-section__title">最新待处理举报</h2>
        <a class="btn btn--ghost btn--sm" href="<?= e(base_url('admin/reports?status=0')) ?>">查看全部</a>
    </div>

    <?php if (!$latest): ?>
        <div class="empty empty--sm">
            <p class="empty__title">暂无待处理举报</p>
            <p class="empty__desc">所有举报都已处理完毕。</p>
        </div>
    <?php else: ?>
        <ul class="report-list">
            <?php foreach ($latest as $report): ?>
                <li class="report-item">
                    <div class="report-item__main">
                        <span class="badge badge--warn"><?= e(\App\Models\Report::reasonLabel((string) $report['reason'])) ?></span>
                        <span class="report-item__target">
                            <?= e(\App\Models\Report::targetLabel((string) $report['target_type'])) ?>：
                            <?php if ((string) $report['target_type'] === \App\Models\Report::TARGET_WORK): ?>
                                <?= e((string) ($report['work_title'] ?? '（作品已删除）')) ?>
                            <?php else: ?>
                                <?= e(mb_substr((string) ($report['comment_content'] ?? '（评论已删除）'), 0, 40)) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <span class="report-item__time"><?= e((string) $report['created_at']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../partials/app_foot.php'; ?>
