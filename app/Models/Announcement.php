<?php
/**
 * 站点公告模型
 *
 * 管理员发布公告，普通用户在站内查看。
 * status = 1 表示已发布（对外可见），0 表示草稿（仅后台可见）。
 *
 * 多租户：公告按 site 隔离，每个分支管理自己的公告，互不影响。
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Model;
use App\Core\Tenant;
use App\Core\TenantScoped;

class Announcement extends Model
{
    use TenantScoped;

    protected static string $table = 'announcements';

    protected static array $fillable = ['site', 'title', 'content', 'status', 'pinned', 'admin_id'];

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
     * 后台分页查询公告列表（仅当前站点）
     *
     * @param array{status?: int|null, keyword?: string} $filters
     * @return array{items: array, total: int, page: int, perPage: int, pages: int}
     */
    public static function paginateForAdmin(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));

        $where = ['a.site = ?'];
        $params = [Tenant::current()];

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
     * 按 ID 查找公告（带出发布人信息；仅当前站点）
     */
    public static function findForAdmin(int $id): ?array
    {
        return DB::first(
            'SELECT a.*,
                    u.nickname AS admin_nickname, u.email AS admin_email
             FROM `announcements` a
             LEFT JOIN `users` u ON u.id = a.admin_id
             WHERE a.site = ? AND a.id = ?
             LIMIT 1',
            [Tenant::current(), $id]
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
                WHERE site = ? AND status = ' . self::STATUS_PUBLISHED . '
                ORDER BY pinned DESC, id DESC';
        $params = [Tenant::current()];
        if ($limit > 0) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }
        return DB::select($sql, $params);
    }

    /**
     * 最新一条已发布公告（用于站内横幅提示）
     */
    public static function latestPublished(): ?array
    {
        return DB::first(
            'SELECT * FROM `announcements`
             WHERE site = ? AND status = ?
             ORDER BY pinned DESC, id DESC
             LIMIT 1',
            [Tenant::current(), self::STATUS_PUBLISHED]
        );
    }

    /**
     * 按状态统计公告数量（仅当前站点）
     */
    public static function countByStatus(int $status): int
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM `announcements` WHERE site = ? AND status = ?',
            [Tenant::current(), $status]
        );
    }

    /**
     * 切换发布状态
     */
    public static function setStatus(int $id, int $status): int
    {
        return DB::execute(
            'UPDATE `announcements` SET status = ? WHERE site = ? AND id = ?',
            [$status, Tenant::current(), $id]
        );
    }

    /**
     * 切换置顶状态
     */
    public static function setPinned(int $id, int $pinned): int
    {
        return DB::execute(
            'UPDATE `announcements` SET pinned = ? WHERE site = ? AND id = ?',
            [$pinned, Tenant::current(), $id]
        );
    }
}
