<?php
/**
 * 管理后台 · 举报处理
 *
 * @var array $user    当前登录管理员
 * @var array $flash   一次性提示消息
 * @var array $reports 举报列表
 * @var array $pager   分页信息
 * @var array $filters 当前筛选条件
 * @var array $counts  各状态数量
 */
$title = '举报处理';
$active = 'admin';
require __DIR__ . '/../partials/app_head.php';

$statusTabs = [
    ''  => ['label' => '全部', 'count' => $counts['all']],
    '0' => ['label' => '待处理', 'count' => $counts['pending']],
    '1' => ['label' => '已处理', 'count' => $counts['resolved']],
    '2' => ['label' => '已驳回', 'count' => $counts['rejected']],
];

/** 拼接保留筛选条件的链接 */
$buildUrl = static function (array $override) use ($filters): string {
    $params = array_filter([
        'status'      => $filters['status'],
        'target_type' => $filters['target_type'],
    ], static fn($v) => $v !== '' && $v !== null);
    foreach ($override as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return base_url('admin/reports' . ($params ? '?' . http_build_query($params) : ''));
};
?>

<div class="page-head">
    <div>
        <h1 class="page-title">举报处理</h1>
        <p class="page-desc">共 <?= (int) $pager['total'] ?> 条记录</p>
    </div>
</div>

<?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>

<div class="admin-tabs">
    <a class="admin-tabs__item" href="<?= e(base_url('admin')) ?>">概览</a>
    <a class="admin-tabs__item is-active" href="<?= e(base_url('admin/reports')) ?>">举报处理</a>
    <a class="admin-tabs__item" href="<?= e(base_url('admin/works')) ?>">作品管理</a>
    <a class="admin-tabs__item" href="<?= e(base_url('admin/users')) ?>">用户管理</a>
</div>

<div class="filter-bar">
    <div class="filter-bar__group">
        <?php foreach ($statusTabs as $key => $tab): ?>
            <a class="filter-chip<?= (string) $filters['status'] === (string) $key ? ' is-active' : '' ?>"
               href="<?= e($buildUrl(['status' => $key, 'page' => null])) ?>">
                <?= e($tab['label']) ?><span class="filter-chip__count"><?= (int) $tab['count'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="filter-bar__group">
        <?php foreach (['' => '全部类型', 'work' => '作品', 'comment' => '评论'] as $key => $label): ?>
            <a class="filter-chip<?= (string) $filters['target_type'] === (string) $key ? ' is-active' : '' ?>"
               href="<?= e($buildUrl(['target_type' => $key, 'page' => null])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!$reports): ?>
    <div class="empty">
        <p class="empty__title">没有符合条件的举报</p>
        <p class="empty__desc">换个筛选条件试试。</p>
    </div>
<?php else: ?>
    <div class="report-cards" id="reportList">
        <?php foreach ($reports as $report): ?>
            <?php
            $status = (int) $report['status'];
            $isWork = (string) $report['target_type'] === \App\Models\Report::TARGET_WORK;
            $statusClass = $status === 0 ? 'badge--warn' : ($status === 1 ? 'badge--public' : 'badge--muted');
            ?>
            <article class="report-card" data-id="<?= (int) $report['id'] ?>">
                <header class="report-card__head">
                    <div class="report-card__tags">
                        <span class="badge <?= $statusClass ?>"><?= e(\App\Models\Report::statusLabel($status)) ?></span>
                        <span class="badge badge--muted"><?= e(\App\Models\Report::targetLabel((string) $report['target_type'])) ?></span>
                        <span class="badge badge--danger"><?= e(\App\Models\Report::reasonLabel((string) $report['reason'])) ?></span>
                    </div>
                    <span class="report-card__time"><?= e((string) $report['created_at']) ?></span>
                </header>

                <div class="report-card__body">
                    <p class="report-card__row">
                        <span class="report-card__key">举报对象</span>
                        <?php if ($isWork): ?>
                            <?php if ($report['work_title'] !== null): ?>
                                <a href="<?= e(base_url('w/' . (string) $report['work_short_code'])) ?>" target="_blank" rel="noopener">
                                    <?= e((string) $report['work_title']) ?>
                                </a>
                                <span class="report-card__sub">#<?= (int) $report['target_id'] ?></span>
                            <?php else: ?>
                                <span class="report-card__gone">作品已删除（#<?= (int) $report['target_id'] ?>）</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if ($report['comment_content'] !== null): ?>
                                <span class="report-card__quote"><?= e(mb_substr((string) $report['comment_content'], 0, 120)) ?></span>
                                <?php if ($report['comment_work_title'] !== null): ?>
                                    <span class="report-card__sub">
                                        来自
                                        <a href="<?= e(base_url('w/' . (string) $report['comment_work_short_code'])) ?>" target="_blank" rel="noopener">
                                            <?= e((string) $report['comment_work_title']) ?>
                                        </a>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="report-card__gone">评论已删除（#<?= (int) $report['target_id'] ?>）</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>

                    <p class="report-card__row">
                        <span class="report-card__key">举报人</span>
                        <?= e($report['reporter_nickname'] !== null && $report['reporter_nickname'] !== ''
                            ? (string) $report['reporter_nickname']
                            : (string) ($report['reporter_email'] ?? '未知')) ?>
                    </p>

                    <?php if ((string) $report['detail'] !== ''): ?>
                        <p class="report-card__row">
                            <span class="report-card__key">补充说明</span>
                            <span class="report-card__detail"><?= e((string) $report['detail']) ?></span>
                        </p>
                    <?php endif; ?>
                </div>

                <footer class="report-card__foot">
                    <?php if ($status === 0): ?>
                        <?php if ($isWork): ?>
                            <button class="btn btn--danger btn--sm js-handle" type="button"
                                    data-id="<?= (int) $report['id'] ?>" data-status="1" data-action="unpublish"
                                    data-confirm="确认标记为已处理并下架该作品？">下架并处理</button>
                        <?php else: ?>
                            <button class="btn btn--danger btn--sm js-handle" type="button"
                                    data-id="<?= (int) $report['id'] ?>" data-status="1" data-action="hide_comment"
                                    data-confirm="确认标记为已处理并隐藏该评论？">隐藏评论并处理</button>
                        <?php endif; ?>
                        <button class="btn btn--ghost btn--sm js-handle" type="button"
                                data-id="<?= (int) $report['id'] ?>" data-status="1" data-action="none">仅标记已处理</button>
                        <button class="btn btn--ghost btn--sm js-handle" type="button"
                                data-id="<?= (int) $report['id'] ?>" data-status="2" data-action="none">驳回举报</button>
                    <?php else: ?>
                        <button class="btn btn--ghost btn--sm js-handle" type="button"
                                data-id="<?= (int) $report['id'] ?>" data-status="0" data-action="none">恢复待处理</button>
                    <?php endif; ?>
                </footer>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($pager['pages'] > 1): ?>
        <nav class="pager">
            <?php for ($i = 1; $i <= $pager['pages']; $i++): ?>
                <?php if ($i === (int) $pager['page']): ?>
                    <span class="pager__item is-active"><?= $i ?></span>
                <?php else: ?>
                    <a class="pager__item" href="<?= e($buildUrl(['page' => $i])) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<script src="<?= e(asset('assets/js/admin.js')) ?>"></script>

<?php require __DIR__ . '/../partials/app_foot.php'; ?>
