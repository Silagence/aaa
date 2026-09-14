<?php
/**
 * 内容举报模型
 *
 * 同一用户对同一对象只能举报一次（唯一索引保证），
 * 重复举报时更新原因与补充说明，便于用户修正描述。
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Model;

class Report extends Model
{
    protected static string $table = 'reports';

    protected static array $fillable = ['target_type', 'target_id', 'user_id', 'reason', 'detail', 'status'];

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
     * 是否已举报过
     */
    public static function existsBy(string $targetType, int $targetId, int $userId): bool
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM `reports`
             WHERE target_type = ? AND target_id = ? AND user_id = ?',
            [$targetType, $targetId, $userId]
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
                 WHERE target_type = ? AND target_id = ? AND user_id = ?',
                [$reason, $detail, $targetType, $targetId, $userId]
            );
            return false;
        }

        DB::execute(
            'INSERT INTO `reports` (`target_type`, `target_id`, `user_id`, `reason`, `detail`, `status`)
             VALUES (?, ?, ?, ?, ?, 0)',
            [$targetType, $targetId, $userId, $reason, $detail]
        );
        return true;
    }

    /**
     * 后台分页查询举报列表
     *
     * 作品举报带出作品标题与短链，评论举报带出评论正文与所属作品，
     * 两者都带出举报人昵称/邮箱，便于管理员判断。
     *
     * @param array{status?: int|null, target_type?: string} $filters
     * @return array{items: array, total: int, page: int, perPage: int, pages: int}
     */
    public static function paginateForAdmin(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));

        $where = [];
        $params = [];

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
             LEFT JOIN `works` w ON r.target_type = \'work\' AND w.id = r.target_id
             LEFT JOIN `comments` c ON r.target_type = \'comment\' AND c.id = r.target_id
             LEFT JOIN `works` cw ON cw.id = c.work_id
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
             LEFT JOIN `works` w ON r.target_type = \'work\' AND w.id = r.target_id
             LEFT JOIN `comments` c ON r.target_type = \'comment\' AND c.id = r.target_id
             LEFT JOIN `works` cw ON cw.id = c.work_id
             WHERE r.id = ?
             LIMIT 1',
            [$id]
        );
    }

    /**
     * 更新举报处理状态
     */
    public static function setStatus(int $id, int $status): int
    {
        return DB::execute('UPDATE `reports` SET status = ? WHERE id = ?', [$status, $id]);
    }

    /**
     * 按状态统计举报数量
     */
    public static function countByStatus(int $status): int
    {
        return (int) DB::value('SELECT COUNT(*) FROM `reports` WHERE status = ?', [$status]);
    }

    /**
     * 统计某对象的举报数量（同一对象被多人举报时用于提示管理员）
     */
    public static function countByTarget(string $targetType, int $targetId): int
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM `reports` WHERE target_type = ? AND target_id = ?',
            [$targetType, $targetId]
        );
    }
}
