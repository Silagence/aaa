<?php
/**
 * 我的收藏（需登录）
 *
 * 仅展示仍处于公开状态的作品，作者取消发布后自动从列表消失。
 *
 * @var array $user  当前登录用户
 * @var array $flash 一次性提示消息
 * @var array $works 收藏的作品列表（含作者信息与 favorited_at）
 * @var array $pager 分页信息
 */
$title = '我的收藏';
$active = 'favorites';
require __DIR__ . '/../partials/app_head.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">我的收藏</h1>
        <p class="page-desc">共 <?= (int) $pager['total'] ?> 份收藏作品</p>
    </div>
    <a class="btn btn--ghost" href="<?= e(base_url('square')) ?>">去广场逛逛</a>
</div>

<?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>

<?php if (!$works): ?>
    <div class="empty">
        <p class="empty__title">还没有收藏作品</p>
        <p class="empty__desc">在作品详情页点击「☆ 收藏」，作品就会出现在这里。</p>
        <a class="btn btn--primary" href="<?= e(base_url('square')) ?>">去作品广场</a>
    </div>
<?php else: ?>
    <div class="work-grid" id="workGrid">
        <?php foreach ($works as $work): ?>
            <?php $workTags = \App\Models\Work::parseTags((string) $work['tags']); ?>
            <article class="work-card" data-id="<?= (int) $work['id'] ?>">
                <a class="work-card__cover" href="<?= e(base_url('w/' . (string) $work['short_code'])) ?>">
                    <?php if ((string) $work['cover'] !== ''): ?>
                        <img src="<?= e(asset((string) $work['cover'])) ?>" alt="<?= e($work['title']) ?>">
                    <?php else: ?>
                        <span class="work-card__placeholder"><?= e(mb_substr((string) $work['title'], 0, 1)) ?></span>
                    <?php endif; ?>
                </a>
                <div class="work-card__body">
                    <h2 class="work-card__title" title="<?= e($work['title']) ?>"><?= e($work['title']) ?></h2>
                    <p class="work-card__meta">
                        <span class="badge badge--public">已发布</span>
                        <?= (int) $work['scene_count'] ?> 个场景 · <?= (int) $work['word_count'] ?> 字
                    </p>
                    <?php if ($workTags): ?>
                        <div class="work-card__tags">
                            <?php foreach ($workTags as $t): ?>
                                <a class="tag-chip" href="<?= e(base_url('square?tag=' . urlencode($t))) ?>"><?= e($t) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <p class="work-card__time work-card__author">
                        <?php $cardAvatar = \App\Services\AvatarService::url($work['author_avatar'] ?? ''); ?>
                        <?php if ($cardAvatar !== ''): ?>
                            <img class="work-card__avatar" src="<?= e($cardAvatar) ?>" alt="">
                        <?php endif; ?>
                        作者：<?= e($work['author_nickname'] !== null && $work['author_nickname'] !== ''
                            ? $work['author_nickname']
                            : (string) $work['author_email']) ?>
                    </p>
                    <p class="work-card__time">
                        收藏于 <?= e((string) $work['favorited_at']) ?>
                    </p>
                </div>
                <div class="work-card__actions">
                    <a class="btn btn--primary btn--sm" href="<?= e(base_url('w/' . (string) $work['short_code'])) ?>">详情</a>
                    <a class="btn btn--ghost btn--sm" href="<?= e(base_url('player?work=' . (int) $work['id'])) ?>"
                       target="_blank" rel="noopener">播放</a>
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
                    <a class="pager__item" href="<?= e(base_url('favorites?page=' . $i)) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../partials/app_foot.php'; ?>
