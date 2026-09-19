<?php
/**
 * 租户隔离 trait
 *
 * 为隔离表模型统一覆盖基类 Model 的 find / create / updateById / deleteById，
 * 使主键查询与写入自动按当前 site 过滤，避免跨站点数据泄漏或误改。
 *
 * 使用方式：在隔离表模型中 `use TenantScoped;`，并确保 $fillable 包含 'site'。
 * 子类仍可覆盖 trait 方法做进一步定制。
 *
 * 注：本 trait 不影响子类自定义的原始 SQL 方法（如 Like::add 使用 DB::execute
 * 直接拼 SQL），那些方法需自行在 SQL 中带上 site 条件。
 */

declare(strict_types=1);

namespace App\Core;

use App\Core\DB;
use App\Core\Tenant;

trait TenantScoped
{
    /**
     * 按主键查找（仅当前站点）
     */
    public static function find(int $id): ?array
    {
        return DB::first(
            sprintf('SELECT * FROM `%s` WHERE site = ? AND id = ? LIMIT 1', static::$table),
            [Tenant::current(), $id]
        );
    }

    /**
     * 创建一行：自动注入当前 site
     */
    public static function create(array $data): int
    {
        $data['site'] = Tenant::current();
        return parent::create($data);
    }

    /**
     * 按主键更新（仅当前站点）
     */
    public static function updateById(int $id, array $data): int
    {
        $data = static::filter($data);
        if ($data === []) {
            return 0;
        }
        return DB::update(
            static::$table,
            $data,
            'site = ? AND id = ?',
            [Tenant::current(), $id]
        );
    }

    /**
     * 按主键删除（仅当前站点）
     */
    public static function deleteById(int $id): int
    {
        return DB::execute(
            sprintf('DELETE FROM `%s` WHERE site = ? AND id = ?', static::$table),
            [Tenant::current(), $id]
        );
    }
}
