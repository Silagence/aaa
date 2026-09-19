<?php
/**
 * 作品收藏模型
 *
 * 收藏关系以 (site, work_id, user_id) 唯一索引保证幂等。
 * 与点赞不同，收藏数不写入 works 表，需要时直接统计。
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Model;
use App\Core\Tenant;
use App\Core\TenantScoped;

class Favorite extends Model
{
    use TenantScoped;

    protected static string $table = 'favorites';

    protected static array $fillable = ['site', 'work_id', 'user_id'];

    /**
     * 是否已收藏
     */
    public static function existsBy(int $workId, int $userId): bool
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM `favorites` WHERE site = ? AND work_id = ? AND user_id = ?',
            [Tenant::current(), $workId, $userId]
        ) > 0;
    }

    /**
     * 收藏（重复收藏不报错）
     *
     * @return bool 本次是否新增了收藏
     */
    public static function add(int $workId, int $userId): bool
    {
        if (self::existsBy($workId, $userId)) {
            return false;
        }
        DB::execute(
            'INSERT INTO `favorites` (`site`, `work_id`, `user_id`) VALUES (?, ?, ?)',
            [Tenant::current(), $workId, $userId]
        );
        return true;
    }

    /**
     * 取消收藏
     *
     * @return bool 本次是否删除了收藏
     */
    public static function remove(int $workId, int $userId): bool
    {
        return DB::execute(
            'DELETE FROM `favorites` WHERE site = ? AND work_id = ? AND user_id = ?',
            [Tenant::current(), $workId, $userId]
        ) > 0;
    }

    /**
     * 统计某作品的收藏数
     */
    public static function countByWork(int $workId): int
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM `favorites` WHERE site = ? AND work_id = ?',
            [Tenant::current(), $workId]
        );
    }

    /**
     * 分页查询某用户收藏的作品（仅保留仍公开可见的作品）
     *
     * 收藏记录与作品都按 site 过滤，避免跨站点串联。
     *
     * @return array{items: array, total: int, page: int, perPage: int, pages: int}
     */
    public static function paginateByUser(int $userId, int $page = 1, int $perPage = 12): array
    {
        $perPage = max(1, min(50, $perPage));

        $where = 'f.site = ? AND f.user_id = ? AND w.status = 1 AND w.is_public = 1';
        $params = [Tenant::current(), $userId];

        $total = (int) DB::value(
            'SELECT COUNT(*)
             FROM `favorites` f
             INNER JOIN `works` w ON w.id = f.work_id AND w.site = f.site
             WHERE ' . $where,
            $params
        );
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        $items = DB::select(
            'SELECT w.id, w.user_id, w.title, w.description, w.cover, w.scene_count, w.word_count,
                    w.is_public, w.short_code, w.tags, w.play_count, w.like_count, w.created_at, w.updated_at,
                    u.nickname AS author_nickname, u.email AS author_email, u.avatar AS author_avatar,
                    f.created_at AS favorited_at
             FROM `favorites` f
             INNER JOIN `works` w ON w.id = f.work_id AND w.site = f.site
             LEFT JOIN `users` u ON u.id = w.user_id
             WHERE ' . $where . '
             ORDER BY f.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'items'   => $items,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => $pages,
        ];
    }
}
