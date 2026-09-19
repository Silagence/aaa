<?php
/**
 * 用户上传素材模型
 *
 * 素材物理文件存放在 storage/uploads/{userId}/{type}/{yyyyMM}/{hash}.{ext}，
 * 由 Nginx 映射到 /uploads/ 对外访问；数据库仅记录元信息。
 *
 * 多租户：所有查询按 site 隔离。每个部署独立 storage/ 目录，
 * 因查询时按 site 过滤，不会跨站点读取到对方素材记录，物理文件天然隔离。
 * 唯一键 (site, user_id, hash) 保证同用户同 hash 在同站点内去重。
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Model;
use App\Core\Tenant;
use App\Core\TenantScoped;

class Asset extends Model
{
    use TenantScoped;

    protected static string $table = 'work_assets';

    protected static array $fillable = [
        'site',
        'user_id',
        'type',
        'name',
        'path',
        'thumb',
        'mime',
        'size',
        'width',
        'height',
        'duration',
        'hash',
        'visibility',
        'license',
        'status',
    ];

    /** 素材类型 → 编辑器素材库分组键 */
    public const TYPE_MAP = [
        'bg'     => 'backgrounds',
        'sprite' => 'sprites',
        'bgm'    => 'bgm',
        'sfx'    => 'sfx',
    ];

    /**
     * 查询某用户的全部有效素材
     */
    public static function listByUser(int $userId): array
    {
        return DB::select(
            'SELECT * FROM `work_assets`
             WHERE site = ? AND user_id = ? AND status = 1
             ORDER BY type ASC, created_at DESC',
            [Tenant::current(), $userId]
        );
    }

    /**
     * 按 ID 与归属用户查找（鉴权用）
     */
    public static function findOwned(int $id, int $userId): ?array
    {
        return DB::first(
            'SELECT * FROM `work_assets` WHERE site = ? AND id = ? AND user_id = ? AND status = 1 LIMIT 1',
            [Tenant::current(), $id, $userId]
        );
    }

    /**
     * 按内容哈希查找同用户已有素材（秒传 / 去重）
     *
     * 不过滤 status：唯一键 (site, user_id, hash) 对软删记录同样生效，
     * 若忽略软删记录会导致重复上传时触发唯一键冲突。
     */
    public static function findByHash(int $userId, string $hash): ?array
    {
        return DB::first(
            'SELECT * FROM `work_assets` WHERE site = ? AND user_id = ? AND hash = ? LIMIT 1',
            [Tenant::current(), $userId, $hash]
        );
    }

    /**
     * 恢复被软删的素材（重新上传同一文件时复用原记录）
     */
    public static function restore(int $id, int $userId, array $data = []): int
    {
        $fields = ['status' => 1];
        foreach (['name', 'visibility', 'license'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = $data[$key];
            }
        }
        return DB::update(
            'work_assets',
            $fields,
            'site = ? AND id = ? AND user_id = ?',
            [Tenant::current(), $id, $userId]
        );
    }

    /**
     * 统计用户已用容量与数量
     *
     * @return array{bytes: int, count: int}
     */
    public static function usage(int $userId): array
    {
        $row = DB::first(
            'SELECT COALESCE(SUM(size), 0) AS bytes, COUNT(*) AS cnt
             FROM `work_assets` WHERE site = ? AND user_id = ? AND status = 1',
            [Tenant::current(), $userId]
        );
        return [
            'bytes' => (int) ($row['bytes'] ?? 0),
            'count' => (int) ($row['cnt'] ?? 0),
        ];
    }

    /**
     * 软删除素材
     */
    public static function softDelete(int $id, int $userId): int
    {
        return DB::execute(
            'UPDATE `work_assets` SET status = 0 WHERE site = ? AND id = ? AND user_id = ? AND status = 1',
            [Tenant::current(), $id, $userId]
        );
    }

    /**
     * 更新素材可见性与版权协议
     */
    public static function updateMeta(int $id, int $userId, array $data): int
    {
        $allowed = [];
        if (array_key_exists('visibility', $data)) {
            $allowed['visibility'] = (int) $data['visibility'] === 1 ? 1 : 0;
        }
        if (array_key_exists('license', $data)) {
            $allowed['license'] = (string) $data['license'];
        }
        if ($allowed === []) {
            return 0;
        }
        return DB::update(
            'work_assets',
            $allowed,
            'site = ? AND id = ? AND user_id = ? AND status = 1',
            [Tenant::current(), $id, $userId]
        );
    }

    /**
     * 扫描某用户所有作品，统计指定素材被引用的作品
     *
     * 作品 JSON 的 assets 段形如 [{type,id,src}]，节点通过 ref 引用素材 id。
     * 这里以 assets 段为准判断引用关系（导出时会把实际引用的素材写入该段）。
     *
     * @param string $assetId 素材在作品中的引用 id（形如 u12_345）
     * @return array<int, array{id: int, title: string}> 引用该素材的作品
     */
    public static function findReferencingWorks(int $userId, string $assetId): array
    {
        if ($assetId === '') {
            return [];
        }

        $rows = DB::select(
            'SELECT id, title, data FROM `works`
             WHERE site = ? AND user_id = ? AND status = 1 AND data IS NOT NULL',
            [Tenant::current(), $userId]
        );

        $hits = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['data'], true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ((array) ($decoded['assets'] ?? []) as $asset) {
                if (is_array($asset) && (string) ($asset['id'] ?? '') === $assetId) {
                    $hits[] = ['id' => (int) $row['id'], 'title' => (string) $row['title']];
                    break;
                }
            }
        }
        return $hits;
    }

    /**
     * 生成素材在作品中的引用 id
     *
     * 加 u{userId}_ 前缀，与内置素材 id 天然隔离，避免冲突。
     * 注：同 userId 在不同站点可能有不同的 asset_id；引用 id 仅在作品 JSON 内部使用，
     * 配合按 site 过滤的素材查找即可保证隔离，无需把 site 编码进 refId。
     */
    public static function refId(int $userId, int $assetId): string
    {
        return 'u' . $userId . '_' . $assetId;
    }
}
