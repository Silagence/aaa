<?php
/**
 * 管理后台 · 用户管理
 *
 * @var array $user    当前登录管理员
 * @var array $flash   一次性提示消息
 * @var array $users   用户列表
 * @var array $pager   分页信息
 * @var array $filters 当前筛选条件
 */
$title = '用户管理';
$active = 'admin';
require __DIR__ . '/../partials/app_head.php';

/** 拼接保留筛选条件的链接 */
$buildUrl = static function (array $override) use ($filters): string {
    $params = array_filter([
        'keyword' => $filters['keyword'],
        'role'    => $filters['role'],
        'status'  => $filters['status'],
    ], static fn($v) => $v !== '' && $v !== null);
    foreach ($override as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return base_url('admin/users' . ($params ? '?' . http_build_query($params) : ''));
};
?>

<div class="page-head">
    <div>
        <h1 class="page-title">用户管理</h1>
        <p class="page-desc">共 <?= (int) $pager['total'] ?> 个账号</p>
    </div>
</div>

<?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>

<div class="admin-tabs">
    <a class="admin-tabs__item" href="<?= e(base_url('admin')) ?>">概览</a>
    <a class="admin-tabs__item" href="<?= e(base_url('admin/reports')) ?>">举报处理</a>
    <a class="admin-tabs__item" href="<?= e(base_url('admin/works')) ?>">作品管理</a>
    <a class="admin-tabs__item is-active" href="<?= e(base_url('admin/users')) ?>">用户管理</a>
    <a class="admin-tabs__item" href="<?= e(base_url('admin/announcements')) ?>">公告管理</a>
</div>

<form class="filter-bar" method="get" action="<?= e(base_url('admin/users')) ?>">
    <div class="filter-bar__group">
        <input class="field__input field__input--inline" type="search" name="keyword"
               value="<?= e((string) $filters['keyword']) ?>" placeholder="搜索邮箱 / 昵称">
        <button class="btn btn--primary btn--sm" type="submit">搜索</button>
        <?php if ((string) $filters['keyword'] !== ''): ?>
            <a class="btn btn--ghost btn--sm" href="<?= e($buildUrl(['keyword' => null, 'page' => null])) ?>">清除</a>
        <?php endif; ?>
    </div>
    <div class="filter-bar__group">
        <?php foreach (['' => '全部角色', 'user' => '普通用户', 'admin' => '管理员'] as $key => $label): ?>
            <a class="filter-chip<?= (string) $filters['role'] === (string) $key ? ' is-active' : '' ?>"
               href="<?= e($buildUrl(['role' => $key, 'page' => null])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <?php foreach (['' => '全部状态', '1' => '正常', '0' => '已禁用'] as $key => $label): ?>
            <a class="filter-chip<?= (string) $filters['status'] === (string) $key ? ' is-active' : '' ?>"
               href="<?= e($buildUrl(['status' => $key, 'page' => null])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</form>

<?php if (!$users): ?>
    <div class="empty">
        <p class="empty__title">没有符合条件的用户</p>
        <p class="empty__desc">换个关键词或筛选条件试试。</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>用户</th>
                    <th>邮箱</th>
                    <th>角色</th>
                    <th>状态</th>
                    <th>最近登录</th>
                    <th>注册时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $row): ?>
                    <?php
                    $isSelf = (int) $row['id'] === (int) $user['id'];
                    $isActive = (int) $row['status'] === 1;
                    $avatar = \App\Services\AvatarService::url((string) $row['avatar']);
                    ?>
                    <tr data-id="<?= (int) $row['id'] ?>">
                        <td class="table__num"><?= (int) $row['id'] ?></td>
                        <td>
                            <span class="table__user">
                                <?php if ($avatar !== ''): ?>
                                    <img class="table__avatar" src="<?= e($avatar) ?>" alt="">
                                <?php endif; ?>
                                <?= e((string) $row['nickname'] !== '' ? (string) $row['nickname'] : '（未设置昵称）') ?>
                                <?php if ($isSelf): ?><span class="table__muted">（我）</span><?php endif; ?>
                            </span>
                        </td>
                        <td><?= e((string) $row['email']) ?></td>
                        <td>
                            <?php if ((string) $row['role'] === 'admin'): ?>
                                <span class="badge badge--accent">管理员</span>
                            <?php else: ?>
                                <span class="badge badge--muted">普通用户</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isActive): ?>
                                <span class="badge badge--public">正常</span>
                            <?php else: ?>
                                <span class="badge badge--danger">已禁用</span>
                            <?php endif; ?>
                        </td>
                        <td class="table__time"><?= e((string) ($row['last_login_at'] ?? '从未登录')) ?></td>
                        <td class="table__time"><?= e((string) $row['created_at']) ?></td>
                        <td class="table__actions">
                            <?php if ($isSelf): ?>
                                <span class="table__muted">—</span>
                            <?php elseif ($isActive): ?>
                                <button class="btn btn--danger btn--sm js-user" type="button"
                                        data-id="<?= (int) $row['id'] ?>" data-status="0"
                                        data-confirm="确认禁用该账号？禁用后该用户将无法登录。">禁用</button>
                            <?php else: ?>
                                <button class="btn btn--ghost btn--sm js-user" type="button"
                                        data-id="<?= (int) $row['id'] ?>" data-status="1">启用</button>
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
