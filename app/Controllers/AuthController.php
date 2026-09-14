<?php
/**
 * 认证控制器
 *
 * 负责注册、登录、登出。所有写操作均校验 CSRF。
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Session;
use App\Services\Auth;
use App\Services\PasswordReset;
use App\Services\RegisterThrottle;

class AuthController extends Controller
{
    /**
     * 登录页
     */
    public function showLogin(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
            return;
        }

        $this->view('auth.login', [
            'user'  => null,
            'flash' => Session::takeFlash(),
        ]);
    }

    /**
     * 处理登录
     */
    public function login(): void
    {
        $this->verifyCsrf();

        $email = (string) $this->input('email', '');
        $password = (string) $this->input('password', '');
        $remember = $this->input('remember') !== null;

        $result = Auth::attempt($email, $password, $remember);

        if (!$result['ok']) {
            Session::flash('error', $result['message']);
            Session::flashInput(['email' => $email]);
            $this->redirect('login');
            return;
        }

        Session::clearOldInput();
        Session::flash('success', '欢迎回来，' . ($result['user']['nickname'] ?: $result['user']['email']));
        $this->redirect('/');
    }

    /**
     * 注册页
     */
    public function showRegister(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
            return;
        }

        $this->view('auth.register', [
            'user'  => null,
            'flash' => Session::takeFlash(),
        ]);
    }

    /**
     * 处理注册
     */
    public function register(): void
    {
        $this->verifyCsrf();

        $email = (string) $this->input('email', '');
        $password = (string) $this->input('password', '');
        $confirm = (string) $this->input('password_confirm', '');
        $nickname = (string) $this->input('nickname', '');

        // 注册限频：按 IP 与邮箱双维度限制，超限直接拒绝
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $throttle = RegisterThrottle::check($ip, $email);
        if (!$throttle['ok']) {
            Session::flash('error', $throttle['message']);
            Session::flashInput(['email' => $email, 'nickname' => $nickname]);
            $this->redirect('register');
            return;
        }
        RegisterThrottle::record($ip, $email);

        if ($password !== $confirm) {
            Session::flash('error', '两次输入的密码不一致');
            Session::flashInput(['email' => $email, 'nickname' => $nickname]);
            $this->redirect('register');
            return;
        }

        $result = Auth::register([
            'email'    => $email,
            'password' => $password,
            'nickname' => $nickname,
        ]);

        if (!$result['ok']) {
            Session::flash('error', $result['message']);
            Session::flashInput(['email' => $email, 'nickname' => $nickname]);
            $this->redirect('register');
            return;
        }

        // 注册成功后直接登录，省去一次输入
        Auth::login($result['user']);
        Session::clearOldInput();
        Session::flash('success', '注册成功，欢迎加入 Dramatool');
        $this->redirect('/');
    }

    /**
     * 忘记密码页（输入邮箱）
     */
    public function showForgot(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
            return;
        }

        $this->view('auth.forgot', [
            'user'  => null,
            'flash' => Session::takeFlash(),
        ]);
    }

    /**
     * 处理忘记密码：校验限频并发送重置邮件
     */
    public function forgot(): void
    {
        $this->verifyCsrf();

        $email = (string) $this->input('email', '');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $result = PasswordReset::request($email, $ip);

        if (!$result['ok']) {
            Session::flash('error', $result['message']);
            Session::flashInput(['email' => $email]);
            $this->redirect('password/forgot');
            return;
        }

        Session::clearOldInput();
        // 开发环境（log 驱动）把重置链接一并提示，方便本地测试
        $message = $result['message'];
        if (!empty($result['debug_link'])) {
            $message .= '（开发环境重置链接：' . $result['debug_link'] . '）';
        }
        Session::flash('success', $message);
        $this->redirect('password/forgot');
    }

    /**
     * 重置密码页（凭邮件中的 token 打开）
     */
    public function showReset(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
            return;
        }

        $token = (string) $this->query('token', '');
        $validated = PasswordReset::validate($token);

        if ($validated === null) {
            Session::flash('error', '重置链接无效或已过期，请重新申请');
            $this->redirect('password/forgot');
            return;
        }

        $this->view('auth.reset', [
            'user'  => null,
            'flash' => Session::takeFlash(),
            'token' => $token,
        ]);
    }

    /**
     * 处理重置密码：更新密码、消费令牌、失效全部登录态
     */
    public function reset(): void
    {
        $this->verifyCsrf();

        $token = (string) $this->input('token', '');
        $password = (string) $this->input('password', '');
        $confirm = (string) $this->input('password_confirm', '');

        $validated = PasswordReset::validate($token);
        if ($validated === null) {
            Session::flash('error', '重置链接无效或已过期，请重新申请');
            $this->redirect('password/forgot');
            return;
        }

        $result = PasswordReset::complete($validated, $password, $confirm);
        if (!$result['ok']) {
            Session::flash('error', $result['message']);
            $this->redirect('password/reset?token=' . rawurlencode($token));
            return;
        }

        Session::flash('success', $result['message']);
        $this->redirect('login');
    }

    /**
     * 登出
     */
    public function logout(): void
    {
        $this->verifyCsrf();

        Auth::logout();
        Session::flash('success', '已退出登录');
        $this->redirect('/');
    }
}
