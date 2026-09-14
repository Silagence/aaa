<?php
/**
 * 找回密码服务
 *
 * 流程：请求重置（邮箱）→ 生成一次性短期令牌 → 邮件发送链接 → 校验令牌 → 重置密码。
 *
 * 安全约定：
 * - 令牌仅存 sha256 哈希，原始 64 位十六进制串只出现在邮件链接中
 * - 令牌默认 1 小时有效，使用后立即标记 used_at，不可重复使用
 * - 同一用户只保留一个待使用令牌：签发新令牌时作废旧令牌
 * - 限频按用户与 IP 双维度滑动窗口计数（复用 password_resets 表）
 * - 无论邮箱是否注册都返回相同提示，防止枚举注册邮箱
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Models\User;

class PasswordReset
{
    /**
     * 请求重置密码
     *
     * @return array{ok: bool, message: string} 无论邮箱是否存在均返回通用提示
     */
    public static function request(string $email, string $ip): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => '请输入有效的邮箱地址'];
        }

        $user = User::findByEmail($email);

        // 先做 IP 维度限频（对不存在的邮箱同样生效，防止用不存在邮箱刷接口）
        $limit = self::throttleMessage($user ? (int) $user['id'] : 0, $ip);
        if ($limit !== null) {
            return ['ok' => false, 'message' => $limit];
        }

        if ($user === null || (int) $user['status'] !== 1) {
            // 不暴露邮箱是否注册；禁用账号也走相同分支
            return ['ok' => true, 'message' => '如果该邮箱已注册，重置链接将在几分钟内发送到邮箱，请注意查收（含垃圾邮件文件夹）'];
        }

        $token = self::issue((int) $user['id'], $ip);
        $link = base_url('password/reset?token=' . rawurlencode($token));
        self::sendMail($user, $link);

        $result = ['ok' => true, 'message' => '如果该邮箱已注册，重置链接将在几分钟内发送到邮箱，请注意查收（含垃圾邮件文件夹）'];

        // 开发环境（log 驱动）下把链接直接带出，避免开发时翻日志
        if (config('mail.driver', 'log') === 'log' && config('app.debug')) {
            $result['debug_link'] = $link;
        }

        return $result;
    }

    /**
     * 校验重置令牌
     *
     * @return array{reset: array, user: array}|null 有效返回记录与用户，否则 null
     */
    public static function validate(string $rawToken): ?array
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '' || !preg_match('/^[0-9a-f]{64}$/', $rawToken)) {
            return null;
        }

        $reset = DB::first(
            'SELECT * FROM `password_resets`
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            [hash('sha256', $rawToken)]
        );
        if ($reset === null) {
            return null;
        }

        $user = User::find((int) $reset['user_id']);
        if ($user === null || (int) $user['status'] !== 1) {
            return null;
        }

        return ['reset' => $reset, 'user' => $user];
    }

    /**
     * 完成密码重置：更新密码、消费令牌、使全部登录态失效
     *
     * @return array{ok: bool, message: string}
     */
    public static function complete(array $validated, string $password, string $confirm): array
    {
        $userId = (int) $validated['user']['id'];

        $min = (int) config('auth.password_min', 8);
        if (mb_strlen($password) < $min) {
            return ['ok' => false, 'message' => '新密码至少需要 ' . $min . ' 位'];
        }
        if (mb_strlen($password) > 72) {
            return ['ok' => false, 'message' => '新密码长度不能超过 72 位'];
        }
        if ($password !== $confirm) {
            return ['ok' => false, 'message' => '两次输入的新密码不一致'];
        }
        if (password_verify($password, (string) $validated['user']['password_hash'])) {
            return ['ok' => false, 'message' => '新密码不能与当前密码相同'];
        }

        DB::transaction(function () use ($validated, $userId, $password): void {
            User::updateById($userId, [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);

            // 消费令牌
            DB::execute(
                'UPDATE `password_resets` SET used_at = NOW()
                 WHERE id = ? AND used_at IS NULL',
                [(int) $validated['reset']['id']]
            );

            // 使该用户所有"记住我"登录态失效
            DB::execute('DELETE FROM `sessions` WHERE user_id = ?', [$userId]);
        });

        return ['ok' => true, 'message' => '密码已重置，请使用新密码登录'];
    }

    /**
     * 签发新令牌：作废旧令牌并写入新记录，返回原始令牌（仅调用方持有）
     */
    private static function issue(int $userId, string $ip): string
    {
        $token = bin2hex(random_bytes(32));
        $expire = (int) config('auth.reset_expire', 3600);

        // 作废旧的待使用令牌，保证同一时刻只有一个有效链接
        DB::execute(
            'UPDATE `password_resets` SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );

        DB::insert('password_resets', [
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'ip'         => substr($ip, 0, 45),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'expires_at' => date('Y-m-d H:i:s', time() + $expire),
        ]);

        return $token;
    }

    /**
     * 滑动窗口限频，超限返回错误提示；未超限返回 null
     */
    private static function throttleMessage(int $userId, string $ip): ?string
    {
        $window = (int) config('auth.reset_window', 3600);
        $threshold = date('Y-m-d H:i:s', time() - $window);
        $minutes = max(1, (int) ceil($window / 60));

        $ipMax = (int) config('auth.reset_ip_max', 10);
        if ($ipMax > 0 && $ip !== '') {
            $count = (int) DB::value(
                'SELECT COUNT(*) FROM `password_resets` WHERE ip = ? AND created_at > ?',
                [$ip, $threshold]
            );
            if ($count >= $ipMax) {
                return '重置请求过于频繁，请 ' . $minutes . ' 分钟后再试';
            }
        }

        $userMax = (int) config('auth.reset_user_max', 3);
        if ($userMax > 0 && $userId > 0) {
            $count = (int) DB::value(
                'SELECT COUNT(*) FROM `password_resets` WHERE user_id = ? AND created_at > ?',
                [$userId, $threshold]
            );
            if ($count >= $userMax) {
                return '重置链接发送次数已达上限，请 ' . $minutes . ' 分钟后再试';
            }
        }

        return null;
    }

    /**
     * 发送重置密码邮件（HTML + 纯文本）
     */
    private static function sendMail(array $user, string $link): void
    {
        $name = (string) ($user['nickname'] !== '' ? $user['nickname'] : $user['email']);
        $minutes = max(1, (int) ceil((int) config('auth.reset_expire', 3600) / 60));
        $appName = (string) config('app.name', 'Dramatool');

        $text = "你好，{$name}：\n\n"
            . "我们收到了重置你 {$appName} 账号密码的请求。\n"
            . "请在 {$minutes} 分钟内点击下面的链接设置新密码：\n\n"
            . $link . "\n\n"
            . "如果不是你本人操作，请忽略这封邮件，你的密码不会发生变化。\n"
            . "该链接仅可使用一次，请勿转发给他人。\n";

        $html = '<div style="max-width:560px;margin:0 auto;font-family:Arial,\'Microsoft YaHei\',sans-serif;'
            . 'color:#1f2937;line-height:1.7">'
            . '<h2 style="margin:0 0 16px;">重置你的 ' . e($appName) . ' 密码</h2>'
            . '<p>你好，' . e($name) . '：</p>'
            . '<p>我们收到了重置你账号密码的请求，请在 ' . $minutes . ' 分钟内点击下方按钮设置新密码：</p>'
            . '<p style="margin:24px 0;">'
            . '<a href="' . e($link) . '" style="display:inline-block;padding:10px 28px;border-radius:8px;'
            . 'background:#6366f1;color:#ffffff;text-decoration:none;font-weight:600;">设置新密码</a>'
            . '</p>'
            . '<p style="font-size:13px;color:#6b7280;word-break:break-all;'
            . '">如果按钮无法点击，也可以复制此链接到浏览器：<br>' . e($link) . '</p>'
            . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0;">'
            . '<p style="font-size:13px;color:#6b7280;">'
            . '如果不是你本人操作，请忽略这封邮件，你的密码不会发生变化。该链接仅可使用一次，请勿转发给他人。'
            . '</p></div>';

        Mailer::send((string) $user['email'], '重置你的 ' . $appName . ' 密码', $html, $text);
    }
}
