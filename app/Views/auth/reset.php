<?php
/**
 * 重置密码页（凭邮件中的 token 打开）
 *
 * @var array  $flash 一次性提示消息
 * @var string $token  有效的重置令牌
 */
$title = '重置密码';
require __DIR__ . '/../partials/auth_head.php';
?>

<h1 class="auth__title">设置新密码</h1>
<p class="auth__subtitle">链接 1 小时内有效，使用后立即失效</p>

<?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>

<form class="form" method="post" action="<?= e(base_url('password/reset')) ?>" novalidate>
    <?= \App\Core\Csrf::field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <label class="field">
        <span class="field__label">新密码</span>
        <input class="field__input" type="password" name="password" required autofocus
               autocomplete="new-password" placeholder="至少 8 位">
    </label>

    <label class="field">
        <span class="field__label">确认新密码</span>
        <input class="field__input" type="password" name="password_confirm" required
               autocomplete="new-password" placeholder="再次输入新密码">
    </label>

    <button class="btn btn--primary btn--block" type="submit">重置密码</button>
</form>

<p class="auth__switch">
    <a class="link" href="<?= e(base_url('login')) ?>">返回登录</a>
</p>

<?php require __DIR__ . '/../partials/auth_foot.php'; ?>
