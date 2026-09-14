<?php
/**
 * 网站公告模型
 *
 * 管理员发布公告，普通用户在站内查看。
 * status = 1 表示已发布（对外可见），0 表示草稿（仅后台可见）。
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Model;

class Announcement extends Model
{
    protected static string $table = 'announcements';

    protected static array $fillable = ['title', 'content', 'status', 'pinned', 'admin_id'];

    /** 发布状态 */
    public const STATUS_DRAFT     = 0;
    public const STATUS_PUBLISHED = 1;

    /** 标题与正文长度上限 */
    public const MAX_TITLE_LENGTH   = 120;
    public const MAX_CONTENT_LENGTH = 5000;

    /**
     * 发布状态白名单：key => 展示文案
     *
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT     => '草稿',
            self::STATUS_PUBLISHED => '已发布',
        ];
    }

    /**
     * 状态展示文案
     */
    public static function statusLabel(int $status): string
    {
        return self::statuses()[$status] ?? '未知';
    }

    /**
     * 后台分页查询公告列表
     *
     * @param array{status?: int|null, keyword?: string} $filters
     * @return array{items: array, total: int, page: int, perPage: int, pages: int}
     */
    public static function paginateForAdmin(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));

        $where = [];
        $params = [];

        $status = $filters['status'] ?? null;
        if ($status !== null && $status !== '') {
            $where[] = 'a.status = ?';
            $params[] = (int) $status;
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $where[] = '(a.title LIKE ? OR a.content LIKE ?)';
            $like = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $clause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $total = (int) DB::value('SELECT COUNT(*) FROM `announcements` a ' . $clause, $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        $items = DB::select(
            'SELECT a.*,
                    u.nickname AS admin_nickname, u.email AS admin_email
             FROM `announcements` a
             LEFT JOIN `users` u ON u.id = a.admin_id
             ' . $clause . '
             ORDER BY a.pinned DESC, a.id DESC
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

    /**
     * 按 ID 查找公告（带出发布人信息）
     */
    public static function findForAdmin(int $id): ?array
    {
        return DB::first(
            'SELECT a.*,
                    u.nickname AS admin_nickname, u.email AS admin_email
             FROM `announcements` a
             LEFT JOIN `users` u ON u.id = a.admin_id
             WHERE a.id = ?
             LIMIT 1',
            [$id]
        );
    }

    /**
     * 已发布公告列表（置顶优先，最新在前）
     *
     * @return array
     */
    public static function published(int $limit = 0): array
    {
        $sql = 'SELECT * FROM `announcements`
                WHERE status = ' . self::STATUS_PUBLISHED . '
                ORDER BY pinned DESC, id DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }
        return DB::select($sql);
    }

    /**
     * 最新一条已发布公告（用于站内横幅提示）
     */
    public static function latestPublished(): ?array
    {
        return DB::first(
            'SELECT * FROM `announcements`
             WHERE status = ?
             ORDER BY pinned DESC, id DESC
             LIMIT 1',
            [self::STATUS_PUBLISHED]
        );
    }

    /**
     * 按状态统计公告数量
     */
    public static function countByStatus(int $status): int
    {
        return (int) DB::value('SELECT COUNT(*) FROM `announcements` WHERE status = ?', [$status]);
    }

    /**
     * 切换发布状态
     */
    public static function setStatus(int $id, int $status): int
    {
        return DB::execute('UPDATE `announcements` SET status = ? WHERE id = ?', [$status, $id]);
    }

    /**
     * 切换置顶状态
     */
    public static function setPinned(int $id, int $pinned): int
    {
        return DB::execute('UPDATE `announcements` SET pinned = ? WHERE id = ?', [$pinned, $id]);
    }
}
