<?php
/**
 * 作品详情页（短链 /w/{code}，无需登录）
 *
 * 输出 OG 标签，便于分享到社交平台时展示标题与简介。
 *
 * @var array|null $user  当前登录用户
 * @var array|null $work  公开作品（含作者信息），不存在时为 null
 * @var string     $code  短链码
 * @var bool       $liked 当前登录用户是否已点赞
 * @var bool       $favorited 当前登录用户是否已收藏
 * @var int        $favoriteTotal 收藏总数
 * @var int        $commentTotal 评论总数
 */
$title = $work !== null ? (string) $work['title'] : '作品不存在';
$active = 'square';
$shareUrl = base_url('w/' . $code);
$authorName = $work !== null
    ? ($work['author_nickname'] !== null && $work['author_nickname'] !== ''
        ? (string) $work['author_nickname']
        : (string) $work['author_email'])
    : '';
$tags = $work !== null ? \App\Models\Work::parseTags((string) $work['tags']) : [];
$authorAvatar = $work !== null ? \App\Services\AvatarService::url($work['author_avatar'] ?? '') : '';

// 分享元信息（OG 标签）
$ogTitle = $work !== null ? (string) $work['title'] : '';
$ogDesc = $work !== null ? trim((string) $work['description']) : '';
if ($ogDesc === '' && $work !== null) {
    $ogDesc = '《' . $work['title'] . '》· 由 ' . $authorName . ' 创作的互动叙事作品';
}
$ogUrl = $shareUrl;

require __DIR__ . '/../partials/app_head.php';
?>

<?php if ($work === null): ?>
    <div class="empty">
        <p class="empty__title">作品不存在或未发布</p>
        <p class="empty__desc">该链接可能已失效，或作者已取消发布。</p>
        <a class="btn btn--primary" href="<?= e(base_url('square')) ?>">去作品广场看看</a>
    </div>
<?php else: ?>
    <div class="detail">
        <div class="detail__stage">
            <div class="detail__cover">
                <?php if ((string) $work['cover'] !== ''): ?>
                    <img src="<?= e(asset('uploads/covers/' . (string) $work['cover'])) ?>" alt="<?= e($work['title']) ?>">
                <?php else: ?>
                    <span class="work-card__placeholder"><?= e(mb_substr((string) $work['title'], 0, 1)) ?></span>
                <?php endif; ?>
            </div>
            <a id="btnPlay" class="btn btn--primary btn--block" href="<?= e(base_url('player?work=' . (int) $work['id'])) ?>"
               target="_blank" rel="noopener">▶ 开始播放</a>
        </div>

        <div class="detail__main">
            <h1 class="detail__title"><?= e($work['title']) ?></h1>

            <div class="detail__author">
                <span class="detail__avatar">
                    <?php if ($authorAvatar !== ''): ?>
                        <img class="detail__avatar-img" src="<?= e($authorAvatar) ?>" alt="">
                    <?php else: ?>
                        <?= e(mb_substr($authorName, 0, 1)) ?>
                    <?php endif; ?>
                </span>
                <span><?= e($authorName) ?></span>
                <span class="detail__dot">·</span>
                <span>更新于 <?= e($work['updated_at']) ?></span>
            </div>

            <?php if ($tags): ?>
                <div class="tag-list">
                    <?php foreach ($tags as $tag): ?>
                        <a class="tag-chip" href="<?= e(base_url('square?tag=' . urlencode($tag))) ?>"><?= e($tag) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (trim((string) $work['description']) !== ''): ?>
                <p class="detail__desc"><?= nl2br(e($work['description'])) ?></p>
            <?php endif; ?>

            <div class="detail__stats">
                <span class="stat"><b id="playCount"><?= (int) $work['play_count'] ?></b> 次播放</span>
                <span class="stat"><b id="likeCount"><?= (int) $work['like_count'] ?></b> 次点赞</span>
                <span class="stat"><b id="favoriteCount"><?= (int) $favoriteTotal ?></b> 次收藏</span>
                <span class="stat"><b><?= (int) $work['scene_count'] ?></b> 个场景</span>
                <span class="stat"><b><?= (int) $work['word_count'] ?></b> 字</span>
            </div>

            <div class="detail__actions">
                <button id="btnLike" class="btn btn--ghost<?= $liked ? ' is-liked' : '' ?>" type="button"
                        data-id="<?= (int) $work['id'] ?>" data-liked="<?= $liked ? '1' : '0' ?>">
                    <span id="likeLabel"><?= $liked ? '♥ 已点赞' : '♡ 点赞' ?></span>
                </button>
                <button id="btnFavorite" class="btn btn--ghost<?= $favorited ? ' is-favorited' : '' ?>" type="button"
                        data-id="<?= (int) $work['id'] ?>" data-favorited="<?= $favorited ? '1' : '0' ?>">
                    <span id="favoriteLabel"><?= $favorited ? '★ 已收藏' : '☆ 收藏' ?></span>
                </button>
                <button id="btnShare" class="btn btn--ghost" type="button">复制链接</button>
                <button id="btnEmbed" class="btn btn--ghost" type="button">嵌入代码</button>
                <button id="btnReport" class="btn btn--ghost btn--report" type="button">举报</button>
            </div>

            <div id="embedBox" class="embed-box" hidden>
                <p class="embed-box__hint">将以下代码粘贴到支持 HTML 的页面即可嵌入播放：</p>
                <textarea id="embedCode" class="field__input field__input--area" readonly rows="3"></textarea>
                <button id="btnCopyEmbed" class="btn btn--primary btn--sm" type="button">复制嵌入代码</button>
            </div>
        </div>
    </div>

    <!-- 评论区 -->
    <section class="comments" id="comments">
        <h2 class="comments__title">
            评论 <span class="comments__count" id="commentCount"><?= (int) $commentTotal ?></span>
        </h2>

        <?php if ($user !== null): ?>
            <form class="comment-form" id="commentForm">
                <textarea class="field__input field__input--area" id="commentInput" name="content"
                          rows="3" maxlength="<?= (int) \App\Models\Comment::MAX_LENGTH ?>"
                          placeholder="说点什么吧…（最多 <?= (int) \App\Models\Comment::MAX_LENGTH ?> 字）"></textarea>
                <div class="comment-form__foot">
                    <span class="comment-form__hint" id="commentHint"></span>
                    <button class="btn btn--primary btn--sm" type="submit" id="btnCommentSubmit">发表评论</button>
                </div>
            </form>
        <?php else: ?>
            <p class="comments__login">
                <a class="link" href="<?= e(base_url('login')) ?>">登录</a> 后即可发表评论。
            </p>
        <?php endif; ?>

        <ul class="comment-list" id="commentList">
            <li class="comment-empty">加载中…</li>
        </ul>

        <div class="comments__more">
            <button class="btn btn--ghost btn--sm" type="button" id="btnMoreComments" hidden>加载更多</button>
        </div>
    </section>

    <div id="toast" class="toast" hidden></div>

    <!-- 举报弹窗（作品与评论共用） -->
    <div class="modal" id="reportModal" hidden>
        <div class="modal__mask" data-close="1"></div>
        <div class="modal__panel" role="dialog" aria-modal="true" aria-labelledby="reportTitle">
            <h2 class="modal__title" id="reportTitle">举报内容</h2>
            <p class="modal__desc" id="reportTarget"></p>

            <form class="form" id="reportForm">
                <div class="field">
                    <span class="field__label">举报原因</span>
                    <div class="report-reasons">
                        <?php foreach (\App\Models\Report::reasons() as $key => $label): ?>
                            <label class="check">
                                <input type="radio" name="reason" value="<?= e((string) $key) ?>">
                                <span><?= e((string) $label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <label class="field">
                    <span class="field__label">补充说明（选填）</span>
                    <textarea class="field__input field__input--area" id="reportDetail" name="detail" rows="3"
                              maxlength="<?= (int) \App\Models\Report::MAX_DETAIL_LENGTH ?>"
                              placeholder="请描述具体问题，便于我们核实"></textarea>
                </label>

                <div class="modal__foot">
                    <button class="btn btn--ghost" type="button" data-close="1">取消</button>
                    <button class="btn btn--danger" type="submit" id="btnReportSubmit">提交举报</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        window.DRAMATOOL_DETAIL = <?= json_encode([
            'workId'    => (int) $work['id'],
            'shareUrl'  => $shareUrl,
            'embedUrl'  => base_url('embed/' . $code),
            'loggedIn'  => $user !== null,
            'maxLength' => (int) \App\Models\Comment::MAX_LENGTH,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="<?= e(asset('assets/js/detail.js')) ?>"></script>
<?php endif; ?>

<?php require __DIR__ . '/../partials/app_foot.php'; ?>
