<?php
/**
 * 数据库连接（PDO 单例）
 *
 * 统一使用预处理语句，禁止拼接 SQL。
 */

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

class DB
{
    private static ?PDO $pdo = null;

    /**
     * 获取 PDO 连接
     */
    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = config('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['database'],
            $cfg['charset']
        );

        try {
            self::$pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('数据库连接失败：' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    /**
     * 执行查询，返回全部行
     */
    public static function select(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * 执行查询，返回首行；无结果返回 null
     */
    public static function first(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * 执行查询，返回首行首列标量值
     */
    public static function value(string $sql, array $params = [])
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * 执行写入语句，返回受影响行数
     */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * 插入一行，返回自增主键
     */
    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn($c) => ':' . $c, $columns);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($data);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * 按主键更新一行，返回受影响行数
     *
     * 使用位置占位符：PDO 关闭模拟预处理（ATTR_EMULATE_PREPARES=false）时，
     * 命名占位符在 MySQL 原生预处理下无法被重复绑定，会导致语句静默影响 0 行。
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = sprintf('`%s` = ?', $column);
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_merge(array_values($data), array_values($whereParams)));
        return $stmt->rowCount();
    }

    /**
     * 事务包装
     */
    public static function transaction(callable $callback)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
