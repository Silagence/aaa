<?php
/**
 * 忘记密码页（输入邮箱，发送重置链接）
 *
 * @var array $flash 一次性提示消息
 */
$title = '找回密码';
require __DIR__ . '/../partials/auth_head.php';
?>

<h1 class="auth__title">找回密码</h1>
<p class="auth__subtitle">输入注册邮箱，我们将发送重置链接</p>

<?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>

<form class="form" method="post" action="<?= e(base_url('password/forgot')) ?>" novalidate>
    <?= \App\Core\Csrf::field() ?>

    <label class="field">
        <span class="field__label">注册邮箱</span>
        <input class="field__input" type="email" name="email" required autofocus
               autocomplete="email" placeholder="you@example.com"
               value="<?= e(old('email')) ?>">
    </label>

    <button class="btn btn--primary btn--block" type="submit">发送重置链接</button>
</form>

<p class="auth__switch">
    <a class="link" href="<?= e(base_url('login')) ?>">返回登录</a>
    &nbsp;·&nbsp;
    还没有账号？<a class="link" href="<?= e(base_url('register')) ?>">立即注册</a>
</p>

<?php require __DIR__ . '/../partials/auth_foot.php'; ?>
