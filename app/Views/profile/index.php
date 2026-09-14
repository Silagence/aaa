<?php
/**
 * 个人中心页
 *
 * @var array $user        当前登录用户
 * @var array $flash       一次性提示消息
 * @var int   $workCount   作品数量
 * @var array $recentWorks 最近作品
 */
$title = '个人中心';
$active = 'profile';
$avatarUrl = \App\Services\AvatarService::url($user['avatar'] ?? '');
$avatarLicense = (string) ($user['avatar_license'] ?? 'original');
$avatarLicenseLabel = \App\Services\AvatarService::licenseLabel($avatarLicense);
$initial = mb_substr($user['nickname'] !== '' ? $user['nickname'] : $user['email'], 0, 1);
$emailVerified = \App\Services\EmailVerification::isVerified($user);
require __DIR__ . '/../partials/app_head.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">个人中心</h1>
        <p class="page-desc">管理你的账号资料与密码</p>
    </div>
</div>

<?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>

<div class="profile">
    <aside class="profile__side">
        <div class="profile__avatar">
            <?php if ($avatarUrl !== ''): ?>
                <img class="profile__avatar-img" src="<?= e($avatarUrl) ?>" alt="头像">
            <?php else: ?>
                <?= e($initial) ?>
            <?php endif; ?>
        </div>

        <form class="avatar-form" method="post" action="<?= e(base_url('profile/avatar')) ?>"
              enctype="multipart/form-data">
            <?= \App\Core\Csrf::field() ?>
            <label class="btn btn--ghost btn--sm avatar-form__pick">
                选择图片
                <input class="avatar-form__file" type="file" name="avatar" accept="image/png,image/jpeg,image/gif,image/webp"
                       onchange="this.form.submit()">
            </label>
            <label class="avatar-form__license">
                <span class="avatar-form__license-label">版权协议</span>
                <select class="field__input field__input--sm" name="license">
                    <?php foreach (\App\Services\AvatarService::licenses() as $key => $label): ?>
                        <option value="<?= e((string) $key) ?>"<?= $key === $avatarLicense ? ' selected' : '' ?>>
                            <?= e((string) $label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="avatar-form__hint">支持 PNG / JPG / GIF / WebP，不超过 2MB</p>
            <p class="avatar-form__notice">
                请确认你拥有该图片的版权或已获得合法授权，不得上传侵犯他人著作权、肖像权的内容。
                因上传内容引发的纠纷由上传者自行承担。
            </p>
        </form>

        <?php if ($avatarUrl !== '' && $avatarLicenseLabel !== ''): ?>
            <p class="avatar-form__current">当前版权：<?= e($avatarLicenseLabel) ?></p>
        <?php endif; ?>

        <?php if ($avatarUrl !== ''): ?>
            <form method="post" action="<?= e(base_url('profile/avatar/delete')) ?>"
                  onsubmit="return confirm('确定移除当前头像吗？');">
                <?= \App\Core\Csrf::field() ?>
                <button class="link link--danger" type="submit">移除头像</button>
            </form>
        <?php endif; ?>

        <p class="profile__name"><?= e($user['nickname'] !== '' ? $user['nickname'] : $user['email']) ?></p>
        <p class="profile__email"><?= e($user['email']) ?></p>
        <dl class="profile__stats">
            <div>
                <dt>作品</dt>
                <dd><?= (int) $workCount ?></dd>
            </div>
            <div>
                <dt>注册于</dt>
                <dd><?= e(mb_substr((string) $user['created_at'], 0, 10)) ?></dd>
            </div>
        </dl>
    </aside>

    <div class="profile__main">
        <section class="card">
            <h2 class="card__title">基本资料</h2>
            <form class="form" method="post" action="<?= e(base_url('profile')) ?>">
                <?= \App\Core\Csrf::field() ?>

                <label class="field">
                    <span class="field__label">昵称</span>
                    <input class="field__input" type="text" name="nickname" required maxlength="50"
                           value="<?= e($user['nickname']) ?>">
                </label>

                <label class="field">
                    <span class="field__label">个人简介</span>
                    <textarea class="field__input field__input--area" name="bio" maxlength="255"
                              rows="3" placeholder="介绍一下自己（选填）"><?= e($user['bio']) ?></textarea>
                </label>

                <label class="field">
                    <span class="field__label">邮箱</span>
                    <input class="field__input" type="email" value="<?= e($user['email']) ?>" disabled>
                    <span class="field__hint">邮箱作为登录账号，暂不支持修改</span>
                </label>

                <div class="field">
                    <span class="field__label">邮箱验证</span>
                    <?php if ($emailVerified): ?>
                        <p class="verify-state verify-state--ok">
                            已验证<span class="verify-state__time">
                                （<?= e(mb_substr((string) $user['email_verified_at'], 0, 10)) ?>）
                            </span>
                        </p>
                    <?php else: ?>
                        <p class="verify-state verify-state--pending">未验证</p>
                        <p class="field__hint">
                            验证邮箱后可确保账号安全，并能在忘记密码时通过邮件找回。
                        </p>
                        <form method="post" action="<?= e(base_url('email/resend')) ?>">
                            <?= \App\Core\Csrf::field() ?>
                            <button class="btn btn--ghost btn--sm" type="submit">重新发送验证邮件</button>
                        </form>
                    <?php endif; ?>
                </div>

                <button class="btn btn--primary" type="submit">保存资料</button>
            </form>
        </section>

        <section class="card">
            <h2 class="card__title">修改密码</h2>
            <form class="form" method="post" action="<?= e(base_url('profile/password')) ?>">
                <?= \App\Core\Csrf::field() ?>

                <label class="field">
                    <span class="field__label">当前密码</span>
                    <input class="field__input" type="password" name="current_password" required
                           autocomplete="current-password">
                </label>

                <label class="field">
                    <span class="field__label">新密码</span>
                    <input class="field__input" type="password" name="new_password" required
                           autocomplete="new-password" placeholder="至少 8 位">
                </label>

                <label class="field">
                    <span class="field__label">确认新密码</span>
                    <input class="field__input" type="password" name="new_password_confirm" required
                           autocomplete="new-password">
                </label>

                <p class="field__hint">修改密码后需要重新登录，其他设备的登录状态也会失效。</p>
                <button class="btn btn--primary" type="submit">修改密码</button>
            </form>
        </section>

        <?php if ($recentWorks): ?>
            <section class="card">
                <h2 class="card__title">最近作品</h2>
                <ul class="mini-list">
                    <?php foreach ($recentWorks as $work): ?>
                        <li class="mini-list__item">
                            <a class="mini-list__title" href="<?= e(base_url('editor?work=' . (int) $work['id'])) ?>">
                                <?= e($work['title']) ?>
                            </a>
                            <span class="mini-list__meta"><?= e($work['updated_at']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a class="link" href="<?= e(base_url('works')) ?>">查看全部作品 →</a>
            </section>
        <?php endif; ?>

        <section class="card card--danger">
            <h2 class="card__title">注销账号</h2>
            <p class="field__hint">
                注销后账号将无法登录，且不可恢复。你的作品会保留在库中，但不再对外展示。
            </p>
            <form class="form" method="post" action="<?= e(base_url('profile/delete')) ?>"
                  onsubmit="return confirm('确定注销账号吗？此操作不可恢复。');">
                <?= \App\Core\Csrf::field() ?>

                <label class="field">
                    <span class="field__label">输入密码以确认</span>
                    <input class="field__input" type="password" name="password" required
                           autocomplete="current-password">
                </label>

                <button class="btn btn--danger" type="submit">注销账号</button>
            </form>
        </section>
    </div>
</div>

<?php require __DIR__ . '/../partials/app_foot.php'; ?>
