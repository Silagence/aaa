<?php
/**
 * 邮箱验证服务
 *
 * 流程：注册后（或手动触发）签发一次性令牌 → 邮件发送验证链接 → 校验令牌 → 标记邮箱已验证。
 *
 * 安全约定：
 * - 令牌仅存 sha256 哈希，原始 64 位十六进制串只出现在邮件链接中
 * - 令牌默认 24 小时有效，使用后立即标记 used_at，不可重复使用
 * - 同一用户只保留一个待使用令牌：签发新令牌时作废旧令牌
 * - 限频按用户与 IP 双维度滑动窗口计数（复用 email_verifications 表）
 * - 已验证的邮箱不再重复签发，避免无意义发信
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Models\User;

class EmailVerification
{
    /**
     * 为指定用户签发验证令牌并发送验证邮件
     *
     * @param array $user 用户记录
     * @return array{ok: bool, message: string, debug_link?: string}
     */
    public static function send(array $user): array
    {
        $userId = (int) $user['id'];
        $email = strtolower(trim((string) $user['email']));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => '账号邮箱无效，无法发送验证邮件'];
        }

        if (self::isVerified($user)) {
            return ['ok' => false, 'message' => '该邮箱已完成验证，无需重复验证'];
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $limit = self::throttleMessage($userId, $ip);
        if ($limit !== null) {
            return ['ok' => false, 'message' => $limit];
        }

        $token = self::issue($userId, $email, $ip);
        $link = base_url('email/verify?token=' . rawurlencode($token));
        self::sendMail($user, $link);

        $result = ['ok' => true, 'message' => '验证邮件已发送，请查收（含垃圾邮件文件夹）'];

        // 开发环境（log 驱动）下把链接直接带出，避免开发时翻日志
        if (config('mail.driver', 'log') === 'log' && config('app.debug')) {
            $result['debug_link'] = $link;
        }

        return $result;
    }

    /**
     * 校验验证令牌
     *
     * @return array{verification: array, user: array}|null 有效返回记录与用户，否则 null
     */
    public static function validate(string $rawToken): ?array
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '' || !preg_match('/^[0-9a-f]{64}$/', $rawToken)) {
            return null;
        }

        $verification = DB::first(
            'SELECT * FROM `email_verifications`
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            [hash('sha256', $rawToken)]
        );
        if ($verification === null) {
            return null;
        }

        $user = User::find((int) $verification['user_id']);
        if ($user === null || (int) $user['status'] !== 1) {
            return null;
        }

        return ['verification' => $verification, 'user' => $user];
    }

    /**
     * 消费令牌并标记邮箱已验证
     *
     * @return array{ok: bool, message: string}
     */
    public static function complete(array $validated): array
    {
        $userId = (int) $validated['user']['id'];

        if (self::isVerified($validated['user'])) {
            // 令牌可能被重复点击，此时视为已完成，不再报错
            return ['ok' => true, 'message' => '邮箱已完成验证'];
        }

        DB::transaction(function () use ($validated, $userId): void {
            User::updateById($userId, ['email_verified_at' => date('Y-m-d H:i:s')]);

            DB::execute(
                'UPDATE `email_verifications` SET used_at = NOW()
                 WHERE id = ? AND used_at IS NULL',
                [(int) $validated['verification']['id']]
            );
        });

        return ['ok' => true, 'message' => '邮箱验证成功，感谢你的确认'];
    }

    /**
     * 用户邮箱是否已验证
     */
    public static function isVerified(array $user): bool
    {
        $at = $user['email_verified_at'] ?? null;
        return $at !== null && $at !== '';
    }

    /**
     * 签发新令牌：作废旧令牌并写入新记录，返回原始令牌（仅调用方持有）
     */
    private static function issue(int $userId, string $email, string $ip): string
    {
        $token = bin2hex(random_bytes(32));
        $expire = (int) config('auth.verify_expire', 86400);

        // 作废旧的待使用令牌，保证同一时刻只有一个有效链接
        DB::execute(
            'UPDATE `email_verifications` SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );

        DB::insert('email_verifications', [
            'user_id'    => $userId,
            'email'      => $email,
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
        $window = (int) config('auth.verify_window', 3600);
        $threshold = date('Y-m-d H:i:s', time() - $window);
        $minutes = max(1, (int) ceil($window / 60));

        $ipMax = (int) config('auth.verify_ip_max', 20);
        if ($ipMax > 0 && $ip !== '') {
            $count = (int) DB::value(
                'SELECT COUNT(*) FROM `email_verifications` WHERE ip = ? AND created_at > ?',
                [$ip, $threshold]
            );
            if ($count >= $ipMax) {
                return '验证邮件发送过于频繁，请 ' . $minutes . ' 分钟后再试';
            }
        }

        $userMax = (int) config('auth.verify_user_max', 5);
        if ($userMax > 0 && $userId > 0) {
            $count = (int) DB::value(
                'SELECT COUNT(*) FROM `email_verifications` WHERE user_id = ? AND created_at > ?',
                [$userId, $threshold]
            );
            if ($count >= $userMax) {
                return '验证邮件发送次数已达上限，请 ' . $minutes . ' 分钟后再试';
            }
        }

        return null;
    }

    /**
     * 发送验证邮件（HTML + 纯文本）
     */
    private static function sendMail(array $user, string $link): void
    {
        $name = (string) ($user['nickname'] !== '' ? $user['nickname'] : $user['email']);
        $hours = max(1, (int) ceil((int) config('auth.verify_expire', 86400) / 3600));
        $appName = (string) config('app.name', 'Dramatool');

        $text = "你好，{$name}：\n\n"
            . "感谢注册 {$appName}。请点击下面的链接完成邮箱验证：\n\n"
            . $link . "\n\n"
            . "链接 {$hours} 小时内有效，仅可使用一次，请勿转发给他人。\n"
            . "如果这不是你本人的操作，请忽略这封邮件。\n";

        $html = '<div style="max-width:560px;margin:0 auto;font-family:Arial,\'Microsoft YaHei\',sans-serif;'
            . 'color:#1f2937;line-height:1.7">'
            . '<h2 style="margin:0 0 16px;">验证你的 ' . e($appName) . ' 邮箱</h2>'
            . '<p>你好，' . e($name) . '：</p>'
            . '<p>感谢注册 ' . e($appName) . '，请点击下方按钮完成邮箱验证：</p>'
            . '<p style="margin:24px 0;">'
            . '<a href="' . e($link) . '" style="display:inline-block;padding:10px 28px;border-radius:8px;'
            . 'background:#6366f1;color:#ffffff;text-decoration:none;font-weight:600;">验证邮箱</a>'
            . '</p>'
            . '<p style="font-size:13px;color:#6b7280;word-break:break-all;'
            . '">如果按钮无法点击，也可以复制此链接到浏览器：<br>' . e($link) . '</p>'
            . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0;">'
            . '<p style="font-size:13px;color:#6b7280;">'
            . '链接 ' . $hours . ' 小时内有效，仅可使用一次，请勿转发给他人。'
            . '如果这不是你本人的操作，请忽略这封邮件。'
            . '</p></div>';

        Mailer::send((string) $user['email'], '验证你的 ' . $appName . ' 邮箱', $html, $text);
    }
}
