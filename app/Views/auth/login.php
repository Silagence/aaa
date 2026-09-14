<?php
/**
 * 登录页
 *
 * @var array $flash 一次性提示消息
 */
$title = '登录';
require __DIR__ . '/../partials/auth_head.php';
?>

<h1 class="auth__title">欢迎回来</h1>
<p class="auth__subtitle">登录后即可将作品保存到云端</p>

<?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>

<form class="form" method="post" action="<?= e(base_url('login')) ?>" novalidate>
    <?= \App\Core\Csrf::field() ?>

    <label class="field">
        <span class="field__label">邮箱</span>
        <input class="field__input" type="email" name="email" required autofocus
               autocomplete="email" placeholder="you@example.com"
               value="<?= e(old('email')) ?>">
    </label>

    <label class="field">
        <span class="field__label">
            密码
            <a class="field__link" href="<?= e(base_url('password/forgot')) ?>">忘记密码？</a>
        </span>
        <input class="field__input" type="password" name="password" required
               autocomplete="current-password" placeholder="请输入密码">
    </label>

    <label class="check">
        <input type="checkbox" name="remember" value="1">
        <span>记住我（30 天内免登录）</span>
    </label>

    <button class="btn btn--primary btn--block" type="submit">登录</button>
</form>

<p class="auth__switch">
    还没有账号？<a class="link" href="<?= e(base_url('register')) ?>">立即注册</a>
</p>

<?php require __DIR__ . '/../partials/auth_foot.php'; ?>
