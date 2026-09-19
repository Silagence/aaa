<?php
/**
 * 内容举报模型
 *
 * 同一用户对同一对象只能举报一次（唯一索引保证），
 * 重复举报时更新原因与补充说明，便于用户修正描述。
 *
 * 多租户：举报记录带 site 字段，admin 查询时按 site 过滤，
 * 跨表 JOIN works/comments 也使用 `w.site = r.site` 避免跨站点串联。
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Model;
use App\Core\Tenant;
use App\Core\TenantScoped;

class Report extends Model
{
    use TenantScoped;

    protected static string $table = 'reports';

    protected static array $fillable = ['site', 'target_type', 'target_id', 'user_id', 'reason', 'detail', 'status'];

    /** 举报对象类型 */
    public const TARGET_WORK = 'work';
    public const TARGET_COMMENT = 'comment';

    /** 处理状态 */
    public const STATUS_PENDING  = 0;
    public const STATUS_RESOLVED = 1;
    public const STATUS_REJECTED = 2;

    /** 补充说明长度上限 */
    public const MAX_DETAIL_LENGTH = 500;

    /**
     * 处理状态白名单：key => 展示文案
     *
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING  => '待处理',
            self::STATUS_RESOLVED => '已处理',
            self::STATUS_REJECTED => '已驳回',
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
     * 举报原因展示文案
     */
    public static function reasonLabel(string $reason): string
    {
        return self::reasons()[$reason] ?? $reason;
    }

    /**
     * 举报对象类型展示文案
     */
    public static function targetLabel(string $targetType): string
    {
        return $targetType === self::TARGET_COMMENT ? '评论' : '作品';
    }

    /**
     * 举报原因白名单：key => 展示文案
     *
     * @return array<string, string>
     */
    public static function reasons(): array
    {
        return [
            'porn'         => '色情低俗',
            'violence'     => '暴力血腥',
            'spam'         => '垃圾广告',
            'infringement' => '侵权盗用',
            'other'        => '其他违规',
        ];
    }

    /**
     * 判断举报原因是否合法
     */
    public static function isValidReason(string $reason): bool
    {
        return array_key_exists($reason, self::reasons());
    }

    /**
     * 是否已举报过（仅查当前站点）
     */
    public static function existsBy(string $targetType, int $targetId, int $userId): bool
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM `reports`
             WHERE site = ? AND target_type = ? AND target_id = ? AND user_id = ?',
            [Tenant::current(), $targetType, $targetId, $userId]
        ) > 0;
    }

    /**
     * 提交举报（重复举报时覆盖原因与说明）
     *
     * @return bool 本次是否为新增举报
     */
    public static function submit(string $targetType, int $targetId, int $userId, string $reason, string $detail): bool
    {
        if (self::existsBy($targetType, $targetId, $userId)) {
            DB::execute(
                'UPDATE `reports` SET reason = ?, detail = ?, status = 0
                 WHERE site = ? AND target_type = ? AND target_id = ? AND user_id = ?',
                [$reason, $detail, Tenant::current(), $targetType, $targetId, $userId]
            );
            return false;
        }

        DB::execute(
            'INSERT INTO `reports` (`site`, `target_type`, `target_id`, `user_id`, `reason`, `detail`, `status`)
             VALUES (?, ?, ?, ?, ?, ?, 0)',
            [Tenant::current(), $targetType, $targetId, $userId, $reason, $detail]
        );
        return true;
    }

    /**
     * 后台分页查询举报列表
     *
     * 作品举报带出作品标题与短链，评论举报带出评论正文与所属作品，
     * 两者都带出举报人昵称/邮箱，便于管理员判断。
     * 跨表 JOIN 全部按 site 过滤，避免跨站点串联。
     *
     * @param array{status?: int|null, target_type?: string} $filters
     * @return array{items: array, total: int, page: int, perPage: int, pages: int}
     */
    public static function paginateForAdmin(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));

        $where = ['r.site = ?'];
        $params = [Tenant::current()];

        $status = $filters['status'] ?? null;
        if ($status !== null && $status !== '') {
            $where[] = 'r.status = ?';
            $params[] = (int) $status;
        }

        $targetType = (string) ($filters['target_type'] ?? '');
        if (in_array($targetType, [self::TARGET_WORK, self::TARGET_COMMENT], true)) {
            $where[] = 'r.target_type = ?';
            $params[] = $targetType;
        }

        $clause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $total = (int) DB::value('SELECT COUNT(*) FROM `reports` r ' . $clause, $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        $items = DB::select(
            'SELECT r.*,
                    u.nickname AS reporter_nickname, u.email AS reporter_email,
                    w.title AS work_title, w.short_code AS work_short_code,
                    c.content AS comment_content, c.work_id AS comment_work_id,
                    cw.title AS comment_work_title, cw.short_code AS comment_work_short_code
             FROM `reports` r
             LEFT JOIN `users` u ON u.id = r.user_id
             LEFT JOIN `works` w ON r.target_type = \'work\' AND w.id = r.target_id AND w.site = r.site
             LEFT JOIN `comments` c ON r.target_type = \'comment\' AND c.id = r.target_id AND c.site = r.site
             LEFT JOIN `works` cw ON cw.id = c.work_id AND cw.site = c.site
             ' . $clause . '
             ORDER BY r.status ASC, r.id DESC
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
     * 按 ID 查找举报（带出对象与举报人信息）
     */
    public static function findForAdmin(int $id): ?array
    {
        return DB::first(
            'SELECT r.*,
                    u.nickname AS reporter_nickname, u.email AS reporter_email,
                    w.title AS work_title, w.short_code AS work_short_code,
                    c.content AS comment_content, c.work_id AS comment_work_id,
                    cw.title AS comment_work_title, cw.short_code AS comment_work_short_code
             FROM `reports` r
             LEFT JOIN `users` u ON u.id = r.user_id
             LEFT JOIN `works` w ON r.target_type = \'work\' AND w.id = r.target_id AND w.site = r.site
             LEFT JOIN `comments` c ON r.target_type = \'comment\' AND c.id = r.target_id AND c.site = r.site
             LEFT JOIN `works` cw ON cw.id = c.work_id AND cw.site = c.site
             WHERE r.site = ? AND r.id = ?
             LIMIT 1',
            [Tenant::current(), $id]
        );
    }

    /**
     * 更新举报处理状态
     */
    public static function setStatus(int $id, int $status): int
    {
        return DB::execute(
            'UPDATE `reports` SET status = ? WHERE site = ? AND id = ?',
            [$status, Tenant::current(), $id]
        );
    }

    /**
     * 按状态统计举报数量（仅当前站点）
     */
    public static function countByStatus(int $status): int
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM `reports` WHERE site = ? AND status = ?',
            [Tenant::current(), $status]
        );
    }

    /**
     * 统计某对象的举报数量（同一对象被多人举报时用于提示管理员）
     */
    public static function countByTarget(string $targetType, int $targetId): int
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM `reports` WHERE site = ? AND target_type = ? AND target_id = ?',
            [Tenant::current(), $targetType, $targetId]
        );
    }
}
