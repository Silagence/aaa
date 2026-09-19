<?php
/**
 * 作品评论模型
 *
 * 评论采用软删除（status = 0），保留记录便于追溯。
 * 列表查询统一 JOIN users 带出作者昵称与头像。
 *
 * 多租户：所有查询按 site 隔离，包括 admin 跨作品 JOIN 也按 site 过滤。
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Model;
use App\Core\Tenant;
use App\Core\TenantScoped;

class Comment extends Model
{
    use TenantScoped;

    protected static string $table = 'comments';

    protected static array $fillable = ['site', 'work_id', 'user_id', 'parent_id', 'content', 'status'];

    /** 单条评论最大字数 */
    public const MAX_LENGTH = 500;

    /** 每条评论最多展示的回复数 */
    public const MAX_REPLIES = 50;

    /**
     * 分页查询某作品的可见顶级评论（按时间倒序）
     *
     * 回复（parent_id > 0）不参与分页，随所属顶级评论一并返回。
     *
     * @return array{items: array, total: int, page: int, perPage: int, pages: int}
     */
    public static function paginateByWork(int $workId, int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(50, $perPage));
        $total = (int) DB::value(
            'SELECT COUNT(*) FROM `comments` WHERE site = ? AND work_id = ? AND parent_id = 0 AND status = 1',
            [Tenant::current(), $workId]
        );
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        $items = DB::select(
            'SELECT c.id, c.work_id, c.user_id, c.parent_id, c.content, c.created_at,
                    u.nickname AS author_nickname, u.email AS author_email, u.avatar AS author_avatar
             FROM `comments` c
             LEFT JOIN `users` u ON u.id = c.user_id
             WHERE c.site = ? AND c.work_id = ? AND c.parent_id = 0 AND c.status = 1
             ORDER BY c.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            [Tenant::current(), $workId]
        );

        return [
            'items'   => $items,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => $pages,
        ];
    }

    /**
     * 查询一批顶级评论下的可见回复（按时间正序，便于阅读）
     *
     * 回复归属于顶级评论，同一站点下 parent_id 不会跨作品错配。
     *
     * @param int[] $parentIds 顶级评论 ID 列表
     * @return array<int, array> 以 parent_id 为键分组的回复列表
     */
    public static function repliesByParents(array $parentIds): array
    {
        $parentIds = array_values(array_unique(array_filter(array_map('intval', $parentIds))));
        if (!$parentIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
        $rows = DB::select(
            'SELECT c.id, c.work_id, c.user_id, c.parent_id, c.content, c.created_at,
                    u.nickname AS author_nickname, u.email AS author_email, u.avatar AS author_avatar
             FROM `comments` c
             LEFT JOIN `users` u ON u.id = c.user_id
             WHERE c.site = ? AND c.parent_id IN (' . $placeholders . ') AND c.status = 1
             ORDER BY c.id ASC',
            array_merge([Tenant::current()], $parentIds)
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['parent_id']][] = $row;
        }
        return $grouped;
    }

    /**
     * 按 ID 查找可见评论（带出作者信息）
     */
    public static function findVisible(int $id): ?array
    {
        return DB::first(
            'SELECT c.id, c.work_id, c.user_id, c.parent_id, c.content, c.created_at,
                    u.nickname AS author_nickname, u.email AS author_email, u.avatar AS author_avatar
             FROM `comments` c
             LEFT JOIN `users` u ON u.id = c.user_id
             WHERE c.site = ? AND c.id = ? AND c.status = 1
             LIMIT 1',
            [Tenant::current(), $id]
        );
    }

    /**
     * 软删除评论；若为顶级评论，同时软删除其下所有回复
     */
    public static function softDelete(int $id): int
    {
        $affected = DB::execute(
            'UPDATE `comments` SET status = 0 WHERE site = ? AND id = ? AND status = 1',
            [Tenant::current(), $id]
        );
        if ($affected > 0) {
            DB::execute(
                'UPDATE `comments` SET status = 0 WHERE site = ? AND parent_id = ? AND status = 1',
                [Tenant::current(), $id]
            );
        }
        return $affected;
    }

    /**
     * 统计某作品的可见评论数（含回复）
     */
    public static function countByWork(int $workId): int
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM `comments` WHERE site = ? AND work_id = ? AND status = 1',
            [Tenant::current(), $workId]
        );
    }
}
