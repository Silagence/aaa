<?php
/**
 * 注册页
 *
 * @var array $flash 一次性提示消息
 */
$title = '注册';
require __DIR__ . '/../partials/auth_head.php';
?>

<h1 class="auth__title">创建账号</h1>
<p class="auth__subtitle">注册后即可保存与管理你的作品</p>

<?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>

<form class="form" method="post" action="<?= e(base_url('register')) ?>" novalidate>
    <?= \App\Core\Csrf::field() ?>

    <label class="field">
        <span class="field__label">邮箱</span>
        <input class="field__input" type="email" name="email" required autofocus
               autocomplete="email" placeholder="you@example.com"
               value="<?= e(old('email')) ?>">
    </label>

    <label class="field">
        <span class="field__label">昵称<span class="field__hint">（选填）</span></span>
        <input class="field__input" type="text" name="nickname" maxlength="50"
               autocomplete="nickname" placeholder="留空则使用邮箱前缀"
               value="<?= e(old('nickname')) ?>">
    </label>

    <label class="field">
        <span class="field__label">密码</span>
        <input class="field__input" type="password" name="password" required
               autocomplete="new-password" placeholder="至少 8 位">
    </label>

    <label class="field">
        <span class="field__label">确认密码</span>
        <input class="field__input" type="password" name="password_confirm" required
               autocomplete="new-password" placeholder="再次输入密码">
    </label>

    <button class="btn btn--primary btn--block" type="submit">注册</button>
</form>

<p class="auth__switch">
    已有账号？<a class="link" href="<?= e(base_url('login')) ?>">直接登录</a>
</p>

<?php require __DIR__ . '/../partials/auth_foot.php'; ?>
