<?php
/**
 * 作品评论模型
 *
 * 评论采用软删除（status = 0），保留记录便于追溯。
 * 列表查询统一 JOIN users 带出作者昵称与头像。
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Model;

class Comment extends Model
{
    protected static string $table = 'comments';

    protected static array $fillable = ['work_id', 'user_id', 'content', 'status'];

    /** 单条评论最大字数 */
    public const MAX_LENGTH = 500;

    /**
     * 分页查询某作品的可见评论（按时间倒序）
     *
     * @return array{items: array, total: int, page: int, perPage: int, pages: int}
     */
    public static function paginateByWork(int $workId, int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(50, $perPage));
        $total = (int) DB::value(
            'SELECT COUNT(*) FROM `comments` WHERE work_id = ? AND status = 1',
            [$workId]
        );
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        $items = DB::select(
            'SELECT c.id, c.work_id, c.user_id, c.content, c.created_at,
                    u.nickname AS author_nickname, u.email AS author_email, u.avatar AS author_avatar
             FROM `comments` c
             LEFT JOIN `users` u ON u.id = c.user_id
             WHERE c.work_id = ? AND c.status = 1
             ORDER BY c.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            [$workId]
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
     * 按 ID 查找可见评论（带出作者信息）
     */
    public static function findVisible(int $id): ?array
    {
        return DB::first(
            'SELECT c.id, c.work_id, c.user_id, c.content, c.created_at,
                    u.nickname AS author_nickname, u.email AS author_email, u.avatar AS author_avatar
             FROM `comments` c
             LEFT JOIN `users` u ON u.id = c.user_id
             WHERE c.id = ? AND c.status = 1
             LIMIT 1',
            [$id]
        );
    }

    /**
     * 软删除评论
     */
    public static function softDelete(int $id): int
    {
        return DB::execute(
            'UPDATE `comments` SET status = 0 WHERE id = ? AND status = 1',
            [$id]
        );
    }

    /**
     * 统计某作品的可见评论数
     */
    public static function countByWork(int $workId): int
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM `comments` WHERE work_id = ? AND status = 1',
            [$workId]
        );
    }
}
