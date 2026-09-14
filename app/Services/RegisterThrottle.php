<?php
/**
 * 注册限频服务
 *
 * 基于数据库的滑动窗口计数，按 IP 与邮箱两个维度限制注册请求频率。
 * 使用数据库而非会话，可跨进程/多机生效，且不受客户端清 Cookie 影响。
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

class RegisterThrottle
{
    /**
     * 检查是否允许本次注册请求
     *
     * @param string $ip    客户端 IP
     * @param string $email 目标邮箱（可为空，为空时只校验 IP 维度）
     * @return array{ok: bool, message: string} ok 为 false 时 message 为提示文案
     */
    public static function check(string $ip, string $email): array
    {
        $window = (int) config('auth.register_window', 3600);

        $ipMax = (int) config('auth.register_ip_max', 5);
        if ($ipMax > 0 && self::count('ip', $ip, $window) >= $ipMax) {
            return ['ok' => false, 'message' => self::limitMessage($window)];
        }

        $emailMax = (int) config('auth.register_email_max', 3);
        if ($emailMax > 0 && $email !== '' && self::count('email', $email, $window) >= $emailMax) {
            return ['ok' => false, 'message' => self::limitMessage($window)];
        }

        return ['ok' => true, 'message' => ''];
    }

    /**
     * 记录一次注册尝试（无论成功与否都计入，防止反复试探）
     */
    public static function record(string $ip, string $email): void
    {
        $rows = [];
        if ($ip !== '') {
            $rows[] = ['scope' => 'ip', 'key_hash' => self::hash($ip)];
        }
        if ($email !== '') {
            $rows[] = ['scope' => 'email', 'key_hash' => self::hash($email)];
        }

        foreach ($rows as $row) {
            DB::insert('register_attempts', $row);
        }

        // 概率性清理过期记录，避免表无限增长
        if (random_int(1, 100) === 1) {
            self::prune();
        }
    }

    /**
     * 统计窗口内某维度的尝试次数
     */
    private static function count(string $scope, string $key, int $window): int
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM `register_attempts`
             WHERE scope = ? AND key_hash = ? AND created_at > ?',
            [$scope, self::hash($key), date('Y-m-d H:i:s', time() - $window)]
        );
    }

    /**
     * 清理超出最大窗口的历史记录
     */
    private static function prune(): void
    {
        $window = max(3600, (int) config('auth.register_window', 3600));
        DB::execute(
            'DELETE FROM `register_attempts` WHERE created_at < ?',
            [date('Y-m-d H:i:s', time() - $window * 2)]
        );
    }

    /**
     * 生成限频提示文案
     */
    private static function limitMessage(int $window): string
    {
        $minutes = max(1, (int) ceil($window / 60));
        return '注册过于频繁，请 ' . $minutes . ' 分钟后再试';
    }

    /**
     * 对标识做哈希，避免明文存储 IP / 邮箱
     */
    private static function hash(string $value): string
    {
        return hash('sha256', strtolower(trim($value)));
    }
}
