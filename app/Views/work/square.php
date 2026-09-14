<?php
/**
 * 作品广场（公开作品列表，无需登录）
 *
 * 支持关键词搜索、标签筛选与最新/最热排序。
 *
 * @var array|null $user    当前登录用户
 * @var array      $flash   一次性提示消息
 * @var array      $works   公开作品列表
 * @var array      $pager   分页信息
 * @var array      $tags    热门标签 [['tag'=>string,'count'=>int], ...]
 * @var string     $keyword 当前搜索关键词
 * @var string     $tag     当前筛选标签
 * @var string     $sort    当前排序：new / hot
 */
$title = '作品广场';
$active = 'square';

/** 拼接保留当前筛选条件的 URL */
$buildUrl = static function (array $override = []) use ($keyword, $tag, $sort): string {
    $params = array_filter([
        'q'    => $keyword,
        'tag'  => $tag,
        'sort' => $sort === 'new' ? '' : $sort,
    ], static fn($v): bool => $v !== '' && $v !== null);
    foreach ($override as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    $qs = http_build_query($params);
    return base_url('square' . ($qs !== '' ? '?' . $qs : ''));
};

require __DIR__ . '/../partials/app_head.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">作品广场</h1>
        <p class="page-desc">共 <?= (int) $pager['total'] ?> 份公开作品</p>
    </div>
    <a class="btn btn--primary" href="<?= e(base_url('editor')) ?>">+ 开始创作</a>
</div>

<?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>

<form class="square-filter" method="get" action="<?= e(base_url('square')) ?>">
    <input class="field__input square-filter__search" type="search" name="q"
           value="<?= e($keyword) ?>" placeholder="搜索作品标题或作者…" autocomplete="off">
    <?php if ($tag !== ''): ?>
        <input type="hidden" name="tag" value="<?= e($tag) ?>">
    <?php endif; ?>
    <?php if ($sort !== 'new'): ?>
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
    <?php endif; ?>
    <button class="btn btn--primary btn--sm" type="submit">搜索</button>
    <div class="square-filter__sorts">
        <a class="sort-link<?= $sort === 'new' ? ' is-active' : '' ?>"
           href="<?= e($buildUrl(['sort' => '', 'page' => ''])) ?>">最新</a>
        <a class="sort-link<?= $sort === 'hot' ? ' is-active' : '' ?>"
           href="<?= e($buildUrl(['sort' => 'hot', 'page' => ''])) ?>">最热</a>
    </div>
</form>

<?php if ($tags): ?>
    <div class="tag-list">
        <?php if ($tag !== ''): ?>
            <a class="tag-chip" href="<?= e($buildUrl(['tag' => '', 'page' => ''])) ?>">× 全部标签</a>
        <?php endif; ?>
        <?php foreach ($tags as $item): ?>
            <a class="tag-chip<?= $tag === $item['tag'] ? ' is-active' : '' ?>"
               href="<?= e($buildUrl(['tag' => $item['tag'], 'page' => ''])) ?>">
                <?= e($item['tag']) ?><span class="tag-chip__count"><?= (int) $item['count'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$works): ?>
    <div class="empty">
        <p class="empty__title">没有找到符合条件的作品</p>
        <p class="empty__desc">
            <?= $keyword !== '' || $tag !== '' ? '换个关键词或标签试试。' : '成为第一个发布作品的人吧。' ?>
        </p>
        <?php if ($keyword !== '' || $tag !== ''): ?>
            <a class="btn btn--ghost" href="<?= e(base_url('square')) ?>">清空筛选</a>
        <?php else: ?>
            <a class="btn btn--primary" href="<?= e(base_url('editor')) ?>">开始创作</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="work-grid" id="workGrid">
        <?php foreach ($works as $work): ?>
            <?php $workTags = \App\Models\Work::parseTags((string) $work['tags']); ?>
            <article class="work-card" data-id="<?= (int) $work['id'] ?>">
                <a class="work-card__cover" href="<?= e(base_url('w/' . (string) $work['short_code'])) ?>">
                    <?php if ((string) $work['cover'] !== ''): ?>
                        <img src="<?= e(asset('uploads/covers/' . (string) $work['cover'])) ?>" alt="<?= e($work['title']) ?>">
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
                                <a class="tag-chip" href="<?= e($buildUrl(['tag' => $t, 'page' => ''])) ?>"><?= e($t) ?></a>
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
                        <?= (int) $work['play_count'] ?> 次播放 · <?= (int) $work['like_count'] ?> 次点赞
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
                    <a class="pager__item" href="<?= e($buildUrl(['page' => $i])) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../partials/app_foot.php'; ?>
