<?php
/**
 * 我的作品列表页
 *
 * @var array $user  当前登录用户
 * @var array $flash 一次性提示消息
 * @var array $works 作品列表
 * @var array $pager 分页信息
 */
$title = '我的作品';
$active = 'works';
require __DIR__ . '/../partials/app_head.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">我的作品</h1>
        <p class="page-desc">共 <?= (int) $pager['total'] ?> 份作品</p>
    </div>
    <a class="btn btn--primary" href="<?= e(base_url('editor')) ?>">+ 新建作品</a>
</div>

<?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>

<?php if (!$works): ?>
    <div class="empty">
        <p class="empty__title">还没有作品</p>
        <p class="empty__desc">点击「新建作品」进入编辑器，开始你的第一个故事。</p>
        <a class="btn btn--primary" href="<?= e(base_url('editor')) ?>">开始创作</a>
    </div>
<?php else: ?>
    <div class="work-grid" id="workGrid">
        <?php foreach ($works as $work): ?>
            <article class="work-card" data-id="<?= (int) $work['id'] ?>">
                <a class="work-card__cover" href="<?= e(base_url('editor?work=' . (int) $work['id'])) ?>">
                    <?php if ($work['cover'] !== ''): ?>
                        <img src="<?= e(asset($work['cover'])) ?>" alt="<?= e($work['title']) ?>">
                    <?php else: ?>
                        <span class="work-card__placeholder"><?= e(mb_substr($work['title'], 0, 1)) ?></span>
                    <?php endif; ?>
                </a>
                <div class="work-card__body">
                    <h2 class="work-card__title" title="<?= e($work['title']) ?>"><?= e($work['title']) ?></h2>
                    <p class="work-card__meta">
                        <span class="badge <?= (int) $work['is_public'] === 1 ? 'badge--public' : '' ?>">
                            <?= (int) $work['is_public'] === 1 ? '已发布' : '未发布' ?>
                        </span>
                        <?= (int) $work['scene_count'] ?> 个场景 · <?= (int) $work['word_count'] ?> 字
                    </p>
                    <p class="work-card__time">更新于 <?= e($work['updated_at']) ?></p>
                </div>
                <div class="work-card__actions">
                    <a class="btn btn--ghost btn--sm" href="<?= e(base_url('editor?work=' . (int) $work['id'])) ?>">编辑</a>
                    <a class="btn btn--ghost btn--sm" href="<?= e(base_url('player?work=' . (int) $work['id'])) ?>"
                       target="_blank" rel="noopener">播放</a>
                    <button class="btn btn--ghost btn--sm js-publish" type="button"
                            data-id="<?= (int) $work['id'] ?>"
                            data-public="<?= (int) $work['is_public'] ?>"
                            data-title="<?= e($work['title']) ?>"><?= (int) $work['is_public'] === 1 ? '取消发布' : '发布' ?></button>
                    <button class="btn btn--ghost btn--sm js-rename" type="button"
                            data-id="<?= (int) $work['id'] ?>"
                            data-title="<?= e($work['title']) ?>">重命名</button>
                    <button class="btn btn--danger btn--sm js-delete" type="button"
                            data-id="<?= (int) $work['id'] ?>"
                            data-title="<?= e($work['title']) ?>">删除</button>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($pager['pages'] > 1): ?>
        <nav class="pager">
            <?php for ($i = 1; $i <= $pager['pages']; $i++): ?>
                <?php if ($i === (int) $pager['page']): ?>
                    <span class="pager__item is-active"><?= $i ?></span>
                <?php else: ?>
                    <a class="pager__item" href="<?= e(base_url('works?page=' . $i)) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<script>
    window.DRAMATOOL_CTX = <?= json_encode([
        'baseUrl'   => base_url('/'),
        'csrfToken' => \App\Core\Csrf::token(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= e(asset('assets/js/theme.js')) ?>"></script>
<script src="<?= e(asset('assets/js/works.js')) ?>"></script>

<?php require __DIR__ . '/../partials/app_foot.php'; ?>
