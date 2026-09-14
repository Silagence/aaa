<?php
/**
 * 管理后台 · 公告管理
 *
 * @var array $user          当前登录管理员
 * @var array $flash         一次性提示消息
 * @var array $announcements 公告列表
 * @var array $pager         分页信息
 * @var array $filters       当前筛选条件
 * @var array $counts        各状态数量
 */
$title = '公告管理';
$active = 'admin';
require __DIR__ . '/../partials/app_head.php';

$statusTabs = [
    ''  => ['label' => '全部', 'count' => $counts['all']],
    '1' => ['label' => '已发布', 'count' => $counts['published']],
    '0' => ['label' => '草稿', 'count' => $counts['draft']],
];

/** 拼接保留筛选条件的链接 */
$buildUrl = static function (array $override) use ($filters): string {
    $params = array_filter([
        'keyword' => $filters['keyword'],
        'status'  => $filters['status'],
    ], static fn($v) => $v !== '' && $v !== null);
    foreach ($override as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return base_url('admin/announcements' . ($params ? '?' . http_build_query($params) : ''));
};
?>

<div class="page-head">
    <div>
        <h1 class="page-title">公告管理</h1>
        <p class="page-desc">共 <?= (int) $pager['total'] ?> 条公告</p>
    </div>
    <button class="btn btn--primary js-ann-new" type="button">+ 发布公告</button>
</div>

<?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>

<div class="admin-tabs">
    <a class="admin-tabs__item" href="<?= e(base_url('admin')) ?>">概览</a>
    <a class="admin-tabs__item" href="<?= e(base_url('admin/reports')) ?>">举报处理</a>
    <a class="admin-tabs__item" href="<?= e(base_url('admin/works')) ?>">作品管理</a>
    <a class="admin-tabs__item" href="<?= e(base_url('admin/users')) ?>">用户管理</a>
    <a class="admin-tabs__item is-active" href="<?= e(base_url('admin/announcements')) ?>">公告管理</a>
</div>

<form class="filter-bar" method="get" action="<?= e(base_url('admin/announcements')) ?>">
    <div class="filter-bar__group">
        <input class="field__input field__input--inline" type="search" name="keyword"
               value="<?= e((string) $filters['keyword']) ?>" placeholder="搜索标题 / 内容">
        <button class="btn btn--primary btn--sm" type="submit">搜索</button>
        <?php if ((string) $filters['keyword'] !== ''): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e($buildUrl(['keyword' => null, 'page' => null])) ?>">清除</a>
        <?php endif; ?>
    </div>
    <div class="filter-bar__group">
        <?php foreach ($statusTabs as $key => $tab): ?>
            <a class="filter-chip<?= (string) $filters['status'] === (string) $key ? ' is-active' : '' ?>"
               href="<?= e($buildUrl(['status' => $key, 'page' => null])) ?>">
                <?= e($tab['label']) ?><span class="filter-chip__count"><?= (int) $tab['count'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</form>

<?php if (!$announcements): ?>
    <div class="empty">
        <p class="empty__title">还没有公告</p>
        <p class="empty__desc">点击「发布公告」向全站用户发布第一条公告。</p>
    </div>
<?php else: ?>
    <div class="report-cards" id="announcementList">
        <?php foreach ($announcements as $item): ?>
            <?php
            $status = (int) $item['status'];
            $isPublished = $status === \App\Models\Announcement::STATUS_PUBLISHED;
            $isPinned = (int) $item['pinned'] === 1;
            $publisher = $item['admin_nickname'] !== null && $item['admin_nickname'] !== ''
                ? (string) $item['admin_nickname']
                : (string) ($item['admin_email'] ?? '未知');
            ?>
            <article class="report-card" data-id="<?= (int) $item['id'] ?>">
                <header class="report-card__head">
                    <div class="report-card__tags">
                        <?php if ($isPublished): ?>
                            <span class="badge badge--public">已发布</span>
                        <?php else: ?>
                            <span class="badge badge--muted">草稿</span>
                        <?php endif; ?>
                        <?php if ($isPinned): ?>
                            <span class="badge badge--accent">置顶</span>
                        <?php endif; ?>
                    </div>
                    <span class="report-card__time"><?= e((string) $item['created_at']) ?></span>
                </header>

                <div class="report-card__body">
                    <h3 class="announcement-card__title"><?= e((string) $item['title']) ?></h3>
                    <p class="announcement-card__content"><?= nl2br(e((string) $item['content'])) ?></p>
                    <p class="report-card__row">
                        <span class="report-card__key">发布人</span>
                        <?= e($publisher) ?>
                        <span class="report-card__sub">更新于 <?= e((string) $item['updated_at']) ?></span>
                    </p>
                </div>

                <footer class="report-card__foot">
                    <button class="btn btn--ghost btn--sm js-ann-edit" type="button"
                            data-id="<?= (int) $item['id'] ?>"
                            data-title="<?= e((string) $item['title']) ?>"
                            data-content="<?= e((string) $item['content']) ?>"
                            data-status="<?= $status ?>"
                            data-pinned="<?= (int) $item['pinned'] ?>">编辑</button>

                    <?php if ($isPublished): ?>
                        <button class="btn btn--ghost btn--sm js-ann-toggle" type="button"
                                data-id="<?= (int) $item['id'] ?>" data-status="0"
                                data-confirm="确认撤回该公告？撤回后普通用户将不再看到。">撤回</button>
                    <?php else: ?>
                        <button class="btn btn--primary btn--sm js-ann-toggle" type="button"
                                data-id="<?= (int) $item['id'] ?>" data-status="1">发布</button>
                    <?php endif; ?>

                    <?php if ($isPinned): ?>
                        <button class="btn btn--ghost btn--sm js-ann-pin" type="button"
                                data-id="<?= (int) $item['id'] ?>" data-pinned="0">取消置顶</button>
                    <?php else: ?>
                        <button class="btn btn--ghost btn--sm js-ann-pin" type="button"
                                data-id="<?= (int) $item['id'] ?>" data-pinned="1">置顶</button>
                    <?php endif; ?>

                    <button class="btn btn--danger btn--sm js-ann-delete" type="button"
                            data-id="<?= (int) $item['id'] ?>"
                            data-confirm="确认删除该公告？删除后不可恢复。">删除</button>
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

<div class="modal" id="annModal" hidden>
    <div class="modal__mask js-ann-cancel"></div>
    <div class="modal__panel">
        <h2 class="modal__title" id="annModalTitle">发布公告</h2>
        <p class="modal__desc">公告发布后，所有登录用户可在「公告」页查看。</p>

        <form class="form" id="annForm">
            <input type="hidden" name="id" value="">
            <label class="field">
                <span class="field__label">标题</span>
                <input class="field__input" type="text" name="title" maxlength="120" required
                       placeholder="例如：站点维护通知">
            </label>
            <label class="field">
                <span class="field__label">
                    内容
                    <span class="field__hint">支持换行，最多 5000 字</span>
                </span>
                <textarea class="field__input field__input--area" name="content" rows="6" maxlength="5000"
                          required placeholder="填写公告正文"></textarea>
            </label>
            <div class="announcement-form__row">
                <label class="check">
                    <input type="checkbox" name="pinned" value="1">
                    <span>置顶显示</span>
                </label>
                <label class="check">
                    <input type="checkbox" name="status" value="1" checked>
                    <span>立即发布（取消则保存为草稿）</span>
                </label>
            </div>
            <div class="modal__foot">
                <button class="btn btn--ghost js-ann-cancel" type="button">取消</button>
                <button class="btn btn--primary" type="submit" id="annSubmit">保存</button>
            </div>
        </form>
    </div>
</div>

<script src="<?= e(asset('assets/js/admin.js')) ?>"></script>

<?php require __DIR__ . '/../partials/app_foot.php'; ?>
