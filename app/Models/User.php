<?php
/**
 * 用户模型
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Model;

class User extends Model
{
    protected static string $table = 'users';

    protected static array $fillable = [
        'email',
        'password_hash',
        'nickname',
        'avatar',
        'avatar_license',
        'bio',
        'role',
        'status',
        'login_fail',
        'locked_until',
        'last_login_at',
        'last_login_ip',
    ];

    /** 角色 */
    public const ROLE_USER  = 'user';
    public const ROLE_ADMIN = 'admin';

    /**
     * 按邮箱查找
     */
    public static function findByEmail(string $email): ?array
    {
        return self::findBy('email', $email);
    }

    /**
     * 后台分页查询用户（可按邮箱/昵称关键词过滤）
     *
     * @param array{keyword?: string, role?: string, status?: int|null} $filters
     * @return array{items: array, total: int, page: int, perPage: int, pages: int}
     */
    public static function paginateForAdmin(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));

        $where = [];
        $params = [];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $where[] = '(email LIKE ? OR nickname LIKE ?)';
            $like = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $role = (string) ($filters['role'] ?? '');
        if (in_array($role, [self::ROLE_USER, self::ROLE_ADMIN], true)) {
            $where[] = 'role = ?';
            $params[] = $role;
        }

        $status = $filters['status'] ?? null;
        if ($status !== null && $status !== '') {
            $where[] = 'status = ?';
            $params[] = (int) $status;
        }

        $clause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $total = (int) DB::value('SELECT COUNT(*) FROM `users` ' . $clause, $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        $items = DB::select(
            'SELECT id, email, nickname, avatar, role, status, last_login_at, created_at
             FROM `users` ' . $clause . '
             ORDER BY id DESC
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
     * 设置用户启用状态（禁用后无法登录，已有会话在下次请求时失效）
     */
    public static function setStatus(int $id, int $status): int
    {
        return DB::execute('UPDATE `users` SET status = ? WHERE id = ?', [$status, $id]);
    }

    /**
     * 设置用户角色
     */
    public static function setRole(int $id, string $role): int
    {
        return DB::execute('UPDATE `users` SET role = ? WHERE id = ?', [$role, $id]);
    }

    /**
     * 统计用户数量
     */
    public static function countAll(?int $status = null): int
    {
        if ($status === null) {
            return (int) DB::value('SELECT COUNT(*) FROM `users`');
        }
        return (int) DB::value('SELECT COUNT(*) FROM `users` WHERE status = ?', [$status]);
    }
}
