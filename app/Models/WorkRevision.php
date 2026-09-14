<?php
/**
 * 作品版本快照模型
 *
 * 每次保存作品时写入一条快照，用于回溯与恢复历史版本。
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Model;

class WorkRevision extends Model
{
    protected static string $table = 'work_revisions';

    protected static array $fillable = [
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
             WHERE work_id = ?
             ORDER BY id DESC
             LIMIT ' . $limit,
            [$workId]
        );
    }

    /**
     * 按 ID 与所属作品查找快照（含快照数据）
     */
    public static function findOwned(int $id, int $workId): ?array
    {
        return DB::first(
            'SELECT * FROM `work_revisions` WHERE id = ? AND work_id = ? LIMIT 1',
            [$id, $workId]
        );
    }

    /**
     * 统计某作品的快照数量
     */
    public static function countByWork(int $workId): int
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM `work_revisions` WHERE work_id = ?',
            [$workId]
        );
    }

    /**
     * 裁剪历史快照，仅保留最近 $keep 条
     */
    public static function prune(int $workId, int $keep = 30): void
    {
        $keep = max(1, $keep);
        $threshold = DB::value(
            'SELECT id FROM `work_revisions` WHERE work_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . ($keep - 1),
            [$workId]
        );
        if ($threshold === null) {
            return;
        }
        DB::execute(
            'DELETE FROM `work_revisions` WHERE work_id = ? AND id < ?',
            [$workId, (int) $threshold]
        );
    }
}
