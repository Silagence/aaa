<?php
/**
 * 作品版本快照模型
 *
 * 每次保存作品时写入一条快照，用于回溯与恢复历史版本。
 * 多租户：所有查询按 site 隔离。
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Model;
use App\Core\Tenant;
use App\Core\TenantScoped;

class WorkRevision extends Model
{
    use TenantScoped;

    protected static string $table = 'work_revisions';

    protected static array $fillable = [
        'site',
        'work_id',
        'user_id',
        'data',
        'remark',
    ];

    /** 列表查询字段（不含体积较大的 data） */
    private const LIST_COLUMNS = 'id, work_id, user_id, remark, created_at';

    /**
     * 某作品的快照列表（不含快照数据）
     */
    public static function listByWork(int $workId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        return DB::select(
            'SELECT ' . self::LIST_COLUMNS . '
             FROM `work_revisions`
             WHERE site = ? AND work_id = ?
             ORDER BY id DESC
             LIMIT ' . $limit,
            [Tenant::current(), $workId]
        );
    }

    /**
     * 按 ID 与所属作品查找快照（含快照数据）
     */
    public static function findOwned(int $id, int $workId): ?array
    {
        return DB::first(
            'SELECT * FROM `work_revisions` WHERE site = ? AND id = ? AND work_id = ? LIMIT 1',
            [Tenant::current(), $id, $workId]
        );
    }

    /**
     * 统计某作品的快照数量
     */
    public static function countByWork(int $workId): int
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM `work_revisions` WHERE site = ? AND work_id = ?',
            [Tenant::current(), $workId]
        );
    }

    /**
     * 裁剪历史快照，仅保留最近 $keep 条
     */
    public static function prune(int $workId, int $keep = 30): void
    {
        $keep = max(1, $keep);
        $threshold = DB::value(
            'SELECT id FROM `work_revisions` WHERE site = ? AND work_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . ($keep - 1),
            [Tenant::current(), $workId]
        );
        if ($threshold === null) {
            return;
        }
        DB::execute(
            'DELETE FROM `work_revisions` WHERE site = ? AND work_id = ? AND id < ?',
            [Tenant::current(), $workId, (int) $threshold]
        );
    }
}
