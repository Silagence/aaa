<?php
/**
 * 认证服务
 *
 * 负责注册、登录态读取、登录/登出、失败锁定、记住我令牌管理。
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Session;
use App\Models\User;

class Auth
{
    private const SESSION_KEY = '_user_id';
    private const COOKIE_KEY  = 'dramatool_remember';

    /** @var array|null 当前请求内缓存的用户数据 */
    private static ?array $cached = null;
    private static bool $resolved = false;

    /**
     * 注册新用户
     *
     * @param array $data email / password / nickname
     * @return array{ok: bool, message: string, user?: array}
     */
    public static function register(array $data): array
    {
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        $nickname = trim((string) ($data['nickname'] ?? ''));

        $error = self::validateRegistration($email, $password, $nickname);
        if ($error !== null) {
            return ['ok' => false, 'message' => $error];
        }

        if (User::exists('email', $email)) {
            return ['ok' => false, 'message' => '该邮箱已被注册'];
        }

        $id = User::create([
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'nickname'      => $nickname !== '' ? $nickname : self::defaultNickname($email),
            'role'          => 'user',
            'status'        => 1,
        ]);

        $user = User::find($id);
        if ($user === null) {
            return ['ok' => false, 'message' => '注册失败，请稍后重试'];
        }

        return ['ok' => true, 'message' => '注册成功', 'user' => $user];
    }

    /**
     * 校验注册输入，返回错误信息；通过则返回 null
     */
    private static function validateRegistration(string $email, string $password, string $nickname): ?string
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '请输入有效的邮箱地址';
        }
        if (mb_strlen($email) > 190) {
            return '邮箱长度超出限制';
        }

        $min = (int) config('auth.password_min', 8);
        if (mb_strlen($password) < $min) {
            return '密码至少需要 ' . $min . ' 位';
        }
        if (mb_strlen($password) > 72) {
            // bcrypt 只取前 72 字节，超出部分会被静默截断
            return '密码长度不能超过 72 位';
        }
        if (mb_strlen($nickname) > 50) {
            return '昵称长度不能超过 50 个字符';
        }
        if ($nickname !== '') {
            $blocked = BlockWord::validate($nickname, '昵称');
            if ($blocked !== null) {
                return $blocked;
            }
        }

        return null;
    }

    /**
     * 由邮箱生成默认昵称
     */
    private static function defaultNickname(string $email): string
    {
        $name = strstr($email, '@', true);
        return $name === false || $name === '' ? '新用户' : $name;
    }

    /**
     * 尝试登录
     *
     * @return array{ok: bool, message: string, user?: array}
     */
    public static function attempt(string $email, string $password, bool $remember = false): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || $password === '') {
            return ['ok' => false, 'message' => '请输入邮箱和密码'];
        }

        $user = User::findByEmail($email);

        // 用户不存在时也执行一次哈希校验，避免通过响应时间枚举邮箱
        if ($user === null) {
            password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            return ['ok' => false, 'message' => '邮箱或密码不正确'];
        }

        if ((int) $user['status'] !== 1) {
            return ['ok' => false, 'message' => '账号已被禁用，请联系管理员'];
        }

        if (self::isLocked($user)) {
            $remain = max(1, (int) ceil((strtotime((string) $user['locked_until']) - time()) / 60));
            return ['ok' => false, 'message' => '登录失败次数过多，请 ' . $remain . ' 分钟后再试'];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            self::recordFailure($user);
            return ['ok' => false, 'message' => '邮箱或密码不正确'];
        }

        // 密码算法升级时自动重算摘要
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            User::updateById((int) $user['id'], [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        }

        self::login($user, $remember);
        return ['ok' => true, 'message' => '登录成功', 'user' => $user];
    }

    /**
     * 账号是否处于锁定期
     */
    private static function isLocked(array $user): bool
    {
        $until = $user['locked_until'] ?? null;
        return $until !== null && $until !== '' && strtotime((string) $until) > time();
    }

    /**
     * 记录一次登录失败，达到上限则锁定账号
     */
    private static function recordFailure(array $user): void
    {
        $fail = (int) $user['login_fail'] + 1;
        $max = (int) config('auth.max_fail', 5);
        $data = ['login_fail' => $fail];

        if ($fail >= $max) {
            $data['locked_until'] = date('Y-m-d H:i:s', time() + (int) config('auth.lock_seconds', 900));
            $data['login_fail'] = 0;
        }

        User::updateById((int) $user['id'], $data);
    }

    /**
     * 更新当前用户资料
     *
     * @return array{ok: bool, message: string}
     */
    public static function updateProfile(array $data): array
    {
        $userId = self::id();
        if ($userId === null) {
            return ['ok' => false, 'message' => '请先登录'];
        }

        $nickname = trim((string) ($data['nickname'] ?? ''));
        $bio = trim((string) ($data['bio'] ?? ''));

        if ($nickname === '') {
            return ['ok' => false, 'message' => '昵称不能为空'];
        }
        if (mb_strlen($nickname) > 50) {
            return ['ok' => false, 'message' => '昵称长度不能超过 50 个字符'];
        }
        $blocked = BlockWord::validate($nickname, '昵称');
        if ($blocked !== null) {
            return ['ok' => false, 'message' => $blocked];
        }
        if (mb_strlen($bio) > 255) {
            return ['ok' => false, 'message' => '个人简介不能超过 255 个字符'];
        }

        User::updateById($userId, ['nickname' => $nickname, 'bio' => $bio]);
        self::$cached = null;
        self::$resolved = false;

        return ['ok' => true, 'message' => '资料已更新'];
    }

    /**
     * 修改当前用户密码
     *
     * @return array{ok: bool, message: string}
     */
    public static function changePassword(string $current, string $new, string $confirm): array
    {
        $user = self::user();
        if ($user === null) {
            return ['ok' => false, 'message' => '请先登录'];
        }

        if (!password_verify($current, (string) $user['password_hash'])) {
            return ['ok' => false, 'message' => '当前密码不正确'];
        }

        $min = (int) config('auth.password_min', 8);
        if (mb_strlen($new) < $min) {
            return ['ok' => false, 'message' => '新密码至少需要 ' . $min . ' 位'];
        }
        if (mb_strlen($new) > 72) {
            return ['ok' => false, 'message' => '新密码长度不能超过 72 位'];
        }
        if ($new !== $confirm) {
            return ['ok' => false, 'message' => '两次输入的新密码不一致'];
        }
        if ($new === $current) {
            return ['ok' => false, 'message' => '新密码不能与当前密码相同'];
        }

        User::updateById((int) $user['id'], [
            'password_hash' => password_hash($new, PASSWORD_DEFAULT),
        ]);

        // 改密后使其他设备的"记住我"令牌全部失效
        DB::execute('DELETE FROM `sessions` WHERE user_id = ?', [(int) $user['id']]);

        return ['ok' => true, 'message' => '密码已修改，请重新登录'];
    }

    /**
     * 注销当前账号
     *
     * 校验密码后软删用户（status=0），并清理登录态与令牌。
     * 作品保留在库中，仅账号不可再登录。
     *
     * @return array{ok: bool, message: string}
     */
    public static function deleteAccount(string $password): array
    {
        $user = self::user();
        if ($user === null) {
            return ['ok' => false, 'message' => '请先登录'];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return ['ok' => false, 'message' => '密码不正确，无法注销账号'];
        }

        $userId = (int) $user['id'];
        User::updateById($userId, ['status' => 0]);
        DB::execute('DELETE FROM `sessions` WHERE user_id = ?', [$userId]);

        self::clearRememberCookie();
        Session::destroy();
        self::$cached = null;
        self::$resolved = true;

        return ['ok' => true, 'message' => '账号已注销'];
    }

    /**
     * 获取当前登录用户，未登录返回 null
     */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$cached;
        }
        self::$resolved = true;

        $userId = Session::get(self::SESSION_KEY);
        if ($userId) {
            $user = User::find((int) $userId);
            if ($user && (int) $user['status'] === 1) {
                self::$cached = $user;
                return self::$cached;
            }
            Session::forget(self::SESSION_KEY);
        }

        // 尝试通过"记住我"令牌恢复登录态
        $user = self::resolveRememberToken();
        if ($user !== null) {
            self::$cached = $user;
            Session::set(self::SESSION_KEY, (int) $user['id']);
        }

        return self::$cached;
    }

    /**
     * 当前用户 ID
     */
    public static function id(): ?int
    {
        $user = self::user();
        return $user ? (int) $user['id'] : null;
    }

    /**
     * 是否已登录
     */
    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * 是否为管理员
     */
    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user !== null && $user['role'] === 'admin';
    }

    /**
     * 标记登录成功
     */
    public static function login(array $user, bool $remember = false): void
    {
        Session::regenerate();
        Session::set(self::SESSION_KEY, (int) $user['id']);
        self::$cached = $user;
        self::$resolved = true;

        User::updateById((int) $user['id'], [
            'login_fail'    => 0,
            'locked_until'  => null,
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => self::clientIp(),
        ]);

        if ($remember) {
            self::issueRememberToken((int) $user['id']);
        }
    }

    /**
     * 登出
     */
    public static function logout(): void
    {
        $userId = self::id();
        if ($userId !== null) {
            DB::execute('DELETE FROM `sessions` WHERE user_id = ?', [$userId]);
        }
        self::clearRememberCookie();
        Session::destroy();
        self::$cached = null;
        self::$resolved = true;
    }

    /**
     * 签发"记住我"令牌
     */
    private static function issueRememberToken(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $lifetime = (int) config('session.remember', 2592000);

        DB::insert('sessions', [
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'ip'         => self::clientIp(),
            'expires_at' => date('Y-m-d H:i:s', time() + $lifetime),
        ]);

        setcookie(self::COOKIE_KEY, $token, [
            'expires'  => time() + $lifetime,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
    }

    /**
     * 通过 Cookie 中的令牌恢复登录态
     */
    private static function resolveRememberToken(): ?array
    {
        $token = $_COOKIE[self::COOKIE_KEY] ?? '';
        if (!is_string($token) || $token === '') {
            return null;
        }

        $row = DB::first(
            'SELECT * FROM `sessions` WHERE token_hash = ? AND expires_at > NOW() LIMIT 1',
            [hash('sha256', $token)]
        );
        if ($row === null) {
            self::clearRememberCookie();
            return null;
        }

        $user = User::find((int) $row['user_id']);
        if ($user === null || (int) $user['status'] !== 1) {
            return null;
        }

        // 令牌轮换：用一次换一次，降低泄露风险
        DB::execute('DELETE FROM `sessions` WHERE id = ?', [(int) $row['id']]);
        self::issueRememberToken((int) $user['id']);

        return $user;
    }

    /**
     * 清除"记住我" Cookie
     */
    private static function clearRememberCookie(): void
    {
        if (isset($_COOKIE[self::COOKIE_KEY])) {
            setcookie(self::COOKIE_KEY, '', time() - 42000, '/');
            unset($_COOKIE[self::COOKIE_KEY]);
        }
    }

    /**
     * 获取客户端 IP
     */
    private static function clientIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }
}
