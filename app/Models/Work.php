<?php
/**
 * 作品模型
 *
 * 多租户：所有查询与写入均按 Tenant::current() 注入 site 字段，
 * 跨站点数据相互隔离；用户身份（users 表）由各站点共享。
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Model;
use App\Core\Tenant;
use App\Core\TenantScoped;

class Work extends Model
{
    use TenantScoped;

    protected static string $table = 'works';

    protected static array $fillable = [
        'site',
        'user_id',
        'title',
        'description',
        'cover',
        'data',
        'scene_count',
        'word_count',
        'status',
        'is_public',
        'short_code',
        'tags',
        'play_count',
        'like_count',
    ];

    /** 列表查询字段（不含体积较大的 data） */
    private const LIST_COLUMNS = 'id, user_id, title, description, cover, scene_count, word_count, is_public, short_code, tags, play_count, like_count, created_at, updated_at';

    /** 短链字符集：去掉易混淆的 0/O/1/I/l */
    private const SHORT_CODE_ALPHABET = '23456789abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';

    private const SHORT_CODE_LENGTH = 8;

    /**
     * 查询某用户的全部作品（不含作品数据，列表用）
     */
    public static function listByUser(int $userId): array
    {
        return DB::select(
            'SELECT ' . self::LIST_COLUMNS . '
             FROM `works`
             WHERE site = ? AND user_id = ? AND status = 1
             ORDER BY updated_at DESC',
            [Tenant::current(), $userId]
        );
    }

    /**
     * 分页查询某用户的作品
     *
     * @return array{items: array, total: int, page: int, perPage: int, pages: int}
     */
    public static function paginateByUser(int $userId, int $page = 1, int $perPage = 12): array
    {
        $perPage = max(1, min(50, $perPage));
        $total = (int) DB::value(
            'SELECT COUNT(*) FROM `works` WHERE site = ? AND user_id = ? AND status = 1',
            [Tenant::current(), $userId]
        );
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        $items = DB::select(
            'SELECT ' . self::LIST_COLUMNS . '
             FROM `works`
             WHERE site = ? AND user_id = ? AND status = 1
             ORDER BY updated_at DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            [Tenant::current(), $userId]
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
     * 按 ID 与所属用户查找（用于鉴权，防止越权访问他人作品）
     */
    public static function findOwned(int $id, int $userId): ?array
    {
        return DB::first(
            'SELECT * FROM `works` WHERE site = ? AND id = ? AND user_id = ? AND status = 1 LIMIT 1',
            [Tenant::current(), $id, $userId]
        );
    }

    /**
     * 软删除作品
     */
    public static function softDelete(int $id, int $userId): int
    {
        return DB::execute(
            'UPDATE `works` SET status = 0 WHERE site = ? AND id = ? AND user_id = ? AND status = 1',
            [Tenant::current(), $id, $userId]
        );
    }

    /**
     * 设置作品的发布状态
     *
     * 注意：MySQL 在值未变化时返回 0 行受影响，因此不能以受影响行数判断是否存在，
     * 需先确认归属再执行更新。
     *
     * 首次发布时生成唯一短链；取消发布保留短链，便于重新发布后链接不失效。
     *
     * @return array{ok: bool, short_code: string} ok 为 false 表示作品不存在或无权操作
     */
    public static function setPublic(int $id, int $userId, bool $public): array
    {
        $work = self::findOwned($id, $userId);
        if ($work === null) {
            return ['ok' => false, 'short_code' => ''];
        }

        $shortCode = (string) ($work['short_code'] ?? '');
        if ($public && $shortCode === '') {
            $shortCode = self::generateShortCode();
        }

        DB::execute(
            'UPDATE `works` SET is_public = ?, short_code = ? WHERE site = ? AND id = ? AND user_id = ? AND status = 1',
            [$public ? 1 : 0, $shortCode !== '' ? $shortCode : null, Tenant::current(), $id, $userId]
        );

        return ['ok' => true, 'short_code' => $shortCode];
    }

    /**
     * 生成未被占用的短链码
     *
     * 随机 8 位（约 57^8 组合），碰撞概率极低；仍做一次存在性检查兜底。
     * 短链在 (site, short_code) 维度唯一，不同站点可复用相同短链。
     */
    private static function generateShortCode(): string
    {
        $alphabet = self::SHORT_CODE_ALPHABET;
        $max = strlen($alphabet) - 1;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = '';
            for ($i = 0; $i < self::SHORT_CODE_LENGTH; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }
            if (!self::existsShortCode($code)) {
                return $code;
            }
        }

        // 极端情况下退化为带时间戳的确定性码，保证唯一
        return substr(base_convert((string) (time() . random_int(100, 999)), 10, 36), 0, self::SHORT_CODE_LENGTH);
    }

    /**
     * 当前站点是否已存在该短链
     */
    private static function existsShortCode(string $code): bool
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM `works` WHERE site = ? AND short_code = ?',
            [Tenant::current(), $code]
        ) > 0;
    }

    /**
     * 按短链码查找已发布的公开作品（仅在当前站点内查找）
     */
    public static function findByShortCode(string $code): ?array
    {
        if ($code === '') {
            return null;
        }
        return DB::first(
            'SELECT * FROM `works` WHERE site = ? AND short_code = ? AND status = 1 AND is_public = 1 LIMIT 1',
            [Tenant::current(), $code]
        );
    }

    /**
     * 按短链码查找公开作品并附带作者信息（详情页用）
     */
    public static function findPublicDetail(string $code): ?array
    {
        if ($code === '') {
            return null;
        }
        return DB::first(
            'SELECT w.*, u.nickname AS author_nickname, u.email AS author_email, u.bio AS author_bio, u.avatar AS author_avatar
             FROM `works` w
             LEFT JOIN `users` u ON u.id = w.user_id
             WHERE w.site = ? AND w.short_code = ? AND w.status = 1 AND w.is_public = 1
             LIMIT 1',
            [Tenant::current(), $code]
        );
    }

    /**
     * 增加播放次数
     */
    public static function incrementPlayCount(int $id): void
    {
        DB::execute(
            'UPDATE `works` SET play_count = play_count + 1 WHERE site = ? AND id = ? AND status = 1',
            [Tenant::current(), $id]
        );
    }

    /**
     * 同步点赞数（以 likes 表实际数量为准，避免计数漂移）
     */
    public static function syncLikeCount(int $id): int
    {
        $count = (int) DB::value(
            'SELECT COUNT(*) FROM `likes` WHERE site = ? AND work_id = ?',
            [Tenant::current(), $id]
        );
        DB::execute(
            'UPDATE `works` SET like_count = ? WHERE site = ? AND id = ?',
            [$count, Tenant::current(), $id]
        );
        return $count;
    }

    /**
     * 解析标签字符串为数组
     */
    public static function parseTags(string $tags): array
    {
        if (trim($tags) === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $tags));
        return array_values(array_filter($parts, static fn(string $t): bool => $t !== ''));
    }

    /**
     * 规范化标签输入：去重、去空、限制数量与单个长度
     */
    public static function normalizeTags(string $input): string
    {
        $parts = preg_split('/[,，\s]+/u', $input) ?: [];
        $tags = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || mb_strlen($part) > 12) {
                continue;
            }
            if (!in_array($part, $tags, true)) {
                $tags[] = $part;
            }
            if (count($tags) >= 5) {
                break;
            }
        }
        return implode(',', $tags);
    }

    /**
     * 查询公开作品中出现过的全部标签及使用次数（按热度降序）
     *
     * @return array<int, array{tag: string, count: int}>
     */
    public static function listPublicTags(int $limit = 20): array
    {
        $rows = DB::select(
            'SELECT tags FROM `works`
             WHERE site = ? AND status = 1 AND is_public = 1 AND tags <> \'\'
             ORDER BY updated_at DESC
             LIMIT 500',
            [Tenant::current()]
        );

        $counter = [];
        foreach ($rows as $row) {
            foreach (self::parseTags((string) $row['tags']) as $tag) {
                $counter[$tag] = ($counter[$tag] ?? 0) + 1;
            }
        }

        arsort($counter);
        $result = [];
        foreach (array_slice($counter, 0, $limit, true) as $tag => $count) {
            $result[] = ['tag' => (string) $tag, 'count' => (int) $count];
        }
        return $result;
    }

    /**
     * 分页查询已发布的公开作品（作品广场）
     *
     * 支持关键词（标题 / 作者）搜索、标签筛选与排序（最新 / 最热）。
     *
     * @param array{keyword?: string, tag?: string, sort?: string} $filters
     * @return array{items: array, total: int, page: int, perPage: int, pages: int}
     */
    public static function paginatePublic(int $page = 1, int $perPage = 12, array $filters = []): array
    {
        $perPage = max(1, min(50, $perPage));

        $where = ['w.site = ?', 'w.status = 1', 'w.is_public = 1'];
        $params = [Tenant::current()];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $where[] = '(w.title LIKE ? OR u.nickname LIKE ? OR u.email LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $tag = trim((string) ($filters['tag'] ?? ''));
        if ($tag !== '') {
            // 标签以逗号分隔存储，用 FIND_IN_SET 精确匹配单个标签，避免 LIKE 误命中子串
            $where[] = 'FIND_IN_SET(?, w.tags)';
            $params[] = $tag;
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) DB::value(
            'SELECT COUNT(*)
             FROM `works` w
             LEFT JOIN `users` u ON u.id = w.user_id
             WHERE ' . $whereSql,
            $params
        );
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        // 排序字段为白名单枚举，不接受外部原始输入
        $orderBy = ($filters['sort'] ?? '') === 'hot'
            ? 'w.like_count DESC, w.play_count DESC, w.updated_at DESC'
            : 'w.updated_at DESC';

        $items = DB::select(
            'SELECT w.id, w.user_id, w.title, w.description, w.cover, w.scene_count, w.word_count,
                    w.is_public, w.short_code, w.tags, w.play_count, w.like_count, w.created_at, w.updated_at,
                    u.nickname AS author_nickname, u.email AS author_email, u.avatar AS author_avatar
             FROM `works` w
             LEFT JOIN `users` u ON u.id = w.user_id
             WHERE ' . $whereSql . '
             ORDER BY ' . $orderBy . '
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
     * 按 ID 查找公开作品并附带作者信息（详情页用）
     */
    public static function findPublicDetailById(int $id): ?array
    {
        return DB::first(
            'SELECT w.*, u.nickname AS author_nickname, u.email AS author_email, u.bio AS author_bio, u.avatar AS author_avatar
             FROM `works` w
             LEFT JOIN `users` u ON u.id = w.user_id
             WHERE w.site = ? AND w.id = ? AND w.status = 1 AND w.is_public = 1
             LIMIT 1',
            [Tenant::current(), $id]
        );
    }

    /**
     * 查找已发布的公开作品（无需登录即可播放）
     */
    public static function findPublic(int $id): ?array
    {
        return DB::first(
            'SELECT * FROM `works` WHERE site = ? AND id = ? AND status = 1 AND is_public = 1 LIMIT 1',
            [Tenant::current(), $id]
        );
    }

    /**
     * 统计某用户的作品数量
     */
    public static function countByUser(int $userId): int
    {
        return (int) DB::value(
            'SELECT COUNT(*) FROM `works` WHERE site = ? AND user_id = ? AND status = 1',
            [Tenant::current(), $userId]
        );
    }

    /**
     * 管理员强制下架作品（取消发布，保留短链与数据）
     *
     * 与作者自行取消发布的区别：不校验归属，仅要求作品存在且未被删除。
     * 仅限本站点范围，避免管理员误操作其他站点作品。
     */
    public static function forceUnpublish(int $id): bool
    {
        return DB::execute(
            'UPDATE `works` SET is_public = 0 WHERE site = ? AND id = ? AND status = 1 AND is_public = 1',
            [Tenant::current(), $id]
        ) > 0;
    }

    /**
     * 管理员软删除作品
     */
    public static function forceDelete(int $id): bool
    {
        return DB::execute(
            'UPDATE `works` SET status = 0, is_public = 0 WHERE site = ? AND id = ? AND status = 1',
            [Tenant::current(), $id]
        ) > 0;
    }

    /**
     * 管理员恢复作品发布状态
     *
     * 短链在首次发布时已生成且取消发布时保留，此处无需重新生成。
     */
    public static function setPublicByAdmin(int $id, bool $public): bool
    {
        return DB::execute(
            'UPDATE `works` SET is_public = ? WHERE site = ? AND id = ? AND status = 1',
            [$public ? 1 : 0, Tenant::current(), $id]
        ) > 0;
    }

    /**
     * 后台分页查询作品（含作者信息，可按标题/作者关键词过滤）
     *
     * 仅返回当前站点的作品，管理员无法跨站点管理。
     *
     * @param array{keyword?: string, status?: int|null} $filters
     * @return array{items: array, total: int, page: int, perPage: int, pages: int}
     */
    public static function paginateForAdmin(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));

        $where = ['w.site = ?'];
        $params = [Tenant::current()];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $where[] = '(w.title LIKE ? OR u.nickname LIKE ? OR u.email LIKE ?)';
            $like = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $status = $filters['status'] ?? null;
        if ($status !== null && $status !== '') {
            $where[] = 'w.status = ?';
            $params[] = (int) $status;
        }

        $clause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $total = (int) DB::value(
            'SELECT COUNT(*) FROM `works` w LEFT JOIN `users` u ON u.id = w.user_id ' . $clause,
            $params
        );
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        $items = DB::select(
            'SELECT w.*, u.nickname AS author_nickname, u.email AS author_email
             FROM `works` w
             LEFT JOIN `users` u ON u.id = w.user_id
             ' . $clause . '
             ORDER BY w.id DESC
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
     * 后台统计：作品总数 / 已发布数（仅当前站点）
     */
    public static function countAll(bool $onlyPublic = false): int
    {
        $sql = 'SELECT COUNT(*) FROM `works` WHERE site = ? AND status = 1';
        $params = [Tenant::current()];
        if ($onlyPublic) {
            $sql .= ' AND is_public = 1';
        }
        return (int) DB::value($sql, $params);
    }
}
