<?php
/**
 * 模型基类
 *
 * 约定：子类通过 $table 指定表名，$fillable 指定可写字段白名单。
 */

declare(strict_types=1);

namespace App\Core;

abstract class Model
{
    /** @var string 表名 */
    protected static string $table = '';

    /** @var array 允许批量写入的字段 */
    protected static array $fillable = [];

    /**
     * 按主键查找
     */
    public static function find(int $id): ?array
    {
        return DB::first(
            sprintf('SELECT * FROM `%s` WHERE id = ? LIMIT 1', static::$table),
            [$id]
        );
    }

    /**
     * 按条件查找首行
     */
    public static function findBy(string $column, $value): ?array
    {
        return DB::first(
            sprintf('SELECT * FROM `%s` WHERE `%s` = ? LIMIT 1', static::$table, $column),
            [$value]
        );
    }

    /**
     * 按条件查询多行
     */
    public static function where(string $column, $value, string $orderBy = 'id DESC', int $limit = 0): array
    {
        $sql = sprintf('SELECT * FROM `%s` WHERE `%s` = ? ORDER BY %s', static::$table, $column, $orderBy);
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }
        return DB::select($sql, [$value]);
    }

    /**
     * 按条件统计行数
     */
    public static function count(string $column, $value): int
    {
        return (int) DB::value(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = ?', static::$table, $column),
            [$value]
        );
    }

    /**
     * 判断记录是否存在
     */
    public static function exists(string $column, $value): bool
    {
        return self::count($column, $value) > 0;
    }

    /**
     * 按主键删除
     */
    public static function deleteById(int $id): int
    {
        return DB::execute(sprintf('DELETE FROM `%s` WHERE id = ?', static::$table), [$id]);
    }

    /**
     * 创建一行，自动过滤非白名单字段
     */
    public static function create(array $data): int
    {
        return DB::insert(static::$table, static::filter($data));
    }

    /**
     * 按主键更新，自动过滤非白名单字段
     */
    public static function updateById(int $id, array $data): int
    {
        $data = static::filter($data);
        if ($data === []) {
            return 0;
        }
        return DB::update(static::$table, $data, 'id = ?', [$id]);
    }

    /**
     * 过滤出白名单字段
     */
    protected static function filter(array $data): array
    {
        if (static::$fillable === []) {
            return $data;
        }
        return array_intersect_key($data, array_flip(static::$fillable));
    }
}
