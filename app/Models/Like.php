<?php
/**
 * 作品点赞模型
 *
 * 点赞关系以 (site, work_id, user_id) 唯一索引保证幂等，
 * works.like_count 仅作冗余计数，写入后统一由 Work::syncLikeCount() 校正。
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Model;
use App\Core\Tenant;
use App\Core\TenantScoped;

class Like extends Model
{
    use TenantScoped;

    protected static string $table = 'likes';

    protected static array $fillable = ['site', 'work_id', 'user_id'];

    /**
     * 是否已点赞（仅查当前站点）
     */
    public static function existsBy(int $workId, int $userId): bool
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM `likes` WHERE site = ? AND work_id = ? AND user_id = ?',
            [Tenant::current(), $workId, $userId]
        ) > 0;
    }

    /**
     * 点赞（重复点赞不报错）
     *
     * @return bool 本次是否新增了点赞
     */
    public static function add(int $workId, int $userId): bool
    {
        if (self::existsBy($workId, $userId)) {
            return false;
        }
        DB::execute(
            'INSERT INTO `likes` (`site`, `work_id`, `user_id`) VALUES (?, ?, ?)',
            [Tenant::current(), $workId, $userId]
        );
        return true;
    }

    /**
     * 取消点赞
     *
     * @return bool 本次是否删除了点赞
     */
    public static function remove(int $workId, int $userId): bool
    {
        return DB::execute(
            'DELETE FROM `likes` WHERE site = ? AND work_id = ? AND user_id = ?',
            [Tenant::current(), $workId, $userId]
        ) > 0;
    }

    /**
     * 批量查询当前用户在一组作品中的点赞状态
     *
     * @param int[] $workIds
     * @return array<int, bool> work_id => liked
     */
    public static function likedMap(array $workIds, int $userId): array
    {
        $workIds = array_values(array_unique(array_filter(array_map('intval', $workIds))));
        if ($workIds === [] || $userId <= 0) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($workIds), '?'));
        $rows = DB::select(
            'SELECT work_id FROM `likes`
             WHERE site = ? AND user_id = ? AND work_id IN (' . $placeholders . ')',
            array_merge([Tenant::current(), $userId], $workIds)
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['work_id']] = true;
        }
        return $map;
    }
}
