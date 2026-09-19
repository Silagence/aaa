<?php
/**
 * 租户上下文
 *
 * 提供当前部署的站点标识（site），用于多分支共用同一数据库时的数据隔离。
 * 站点标识来自 config/local.php 的 app.site，缺失时回落为 'default'。
 *
 * 使用方式：
 *   Tenant::current()  // 取当前 site 字符串，已缓存
 *   Tenant::reset()    // 测试或 CLI 多进程场景下重置缓存
 *
 * 隔离表查询与写入均通过本类注入 site，避免跨站点数据泄漏。
 */

declare(strict_types=1);

namespace App\Core;

final class Tenant
{
    /** @var string|null 当前请求内缓存的 site 标识 */
    private static ?string $site = null;

    /**
     * 取当前站点标识
     *
     * 首次调用时从配置读取并缓存，后续直接返回缓存值。
     * 长度上限 32（与 schema 的 VARCHAR(32) 对齐），超长会被截断；
     * 空值回落为 'default'，保证历史数据兼容。
     */
    public static function current(): string
    {
        if (self::$site === null) {
            $site = (string) (config('app.site') ?? '');
            // 截断至 schema 允许的长度，避免写入时被 MySQL 报错
            if (mb_strlen($site) > 32) {
                $site = mb_substr($site, 0, 32);
            }
            if ($site === '') {
                $site = 'default';
            }
            self::$site = $site;
        }
        return self::$site;
    }

    /**
     * 重置缓存（仅在测试 / 长寿命 CLI 进程切换配置时需要）
     */
    public static function reset(): void
    {
        self::$site = null;
    }
}
