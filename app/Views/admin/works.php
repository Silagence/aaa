<?php
/**
 * 管理后台 · 作品管理
 *
 * @var array $user    当前登录管理员
 * @var array $flash   一次性提示消息
 * @var array $works   作品列表
 * @var array $pager   分页信息
 * @var array $filters 当前筛选条件
 */
$title = '作品管理';
$active = 'admin';
require __DIR__ . '/../partials/app_head.php';

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
    return base_url('admin/works' . ($params ? '?' . http_build_query($params) : ''));
};
?>

<div class="page-head">
    <div>
        <h1 class="page-title">作品管理</h1>
        <p class="page-desc">共 <?= (int) $pager['total'] ?> 份作品</p>
    </div>
</div>

<?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>

<div class="admin-tabs">
    <a class="admin-tabs__item" href="<?= e(base_url('admin')) ?>">概览</a>
    <a class="admin-tabs__item" href="<?= e(base_url('admin/reports')) ?>">举报处理</a>
    <a class="admin-tabs__item is-active" href="<?= e(base_url('admin/works')) ?>">作品管理</a>
    <a class="admin-tabs__item" href="<?= e(base_url('admin/users')) ?>">用户管理</a>
</div>

<form class="filter-bar" method="get" action="<?= e(base_url('admin/works')) ?>">
    <div class="filter-bar__group">
        <input class="field__input field__input--inline" type="search" name="keyword"
               value="<?= e((string) $filters['keyword']) ?>" placeholder="搜索标题 / 作者昵称 / 邮箱">
        <button class="btn btn--primary btn--sm" type="submit">搜索</button>
        <?php if ((string) $filters['keyword'] !== ''): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e($buildUrl(['keyword' => null, 'page' => null])) ?>">清除</a>
        <?php endif; ?>
    </div>
    <div class="filter-bar__group">
        <?php foreach (['' => '全部', '1' => '正常', '0' => '已删除'] as $key => $label): ?>
            <a class="filter-chip<?= (string) $filters['status'] === (string) $key ? ' is-active' : '' ?>"
               href="<?= e($buildUrl(['status' => $key, 'page' => null])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</form>

<?php if (!$works): ?>
    <div class="empty">
        <p class="empty__title">没有符合条件的作品</p>
        <p class="empty__desc">换个关键词或筛选条件试试。</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>标题</th>
                    <th>作者</th>
                    <th>状态</th>
                    <th>数据</th>
                    <th>更新时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($works as $work): ?>
                    <?php
                    $isDeleted = (int) $work['status'] !== 1;
                    $isPublic = (int) $work['is_public'] === 1;
                    ?>
                    <tr data-id="<?= (int) $work['id'] ?>">
                        <td class="table__num"><?= (int) $work['id'] ?></td>
                        <td>
                            <?php if (!$isDeleted && $isPublic && (string) $work['short_code'] !== ''): ?>
                                <a href="<?= e(base_url('w/' . (string) $work['short_code'])) ?>" target="_blank" rel="noopener">
                                    <?= e((string) $work['title']) ?>
                                </a>
                            <?php else: ?>
                                <?= e((string) $work['title']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e($work['author_nickname'] !== null && $work['author_nickname'] !== ''
                                ? (string) $work['author_nickname']
                                : (string) ($work['author_email'] ?? '未知')) ?>
                        </td>
                        <td>
                            <?php if ($isDeleted): ?>
                                <span class="badge badge--muted">已删除</span>
                            <?php elseif ($isPublic): ?>
                                <span class="badge badge--public">已发布</span>
                            <?php else: ?>
                                <span class="badge badge--warn">未发布</span>
                            <?php endif; ?>
                        </td>
                        <td class="table__num"><?= (int) $work['scene_count'] ?> 场景 / <?= (int) $work['word_count'] ?> 字</td>
                        <td class="table__time"><?= e((string) $work['updated_at']) ?></td>
                        <td class="table__actions">
                            <?php if (!$isDeleted): ?>
                                <?php if ($isPublic): ?>
                                    <button class="btn btn--danger btn--sm js-work" type="button"
                                            data-id="<?= (int) $work['id'] ?>" data-action="unpublish"
                                            data-confirm="确认下架《<?= e((string) $work['title']) ?>》？作者将无法通过短链访问。">下架</button>
                                <?php else: ?>
                                    <button class="btn btn--ghost btn--sm js-work" type="button"
                                            data-id="<?= (int) $work['id'] ?>" data-action="restore">恢复发布</button>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="table__muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
