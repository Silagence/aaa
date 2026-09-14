<?php
/**
 * 个人中心控制器
 *
 * 展示与修改个人资料、修改密码。
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Session;
use App\Models\Work;
use App\Services\Auth;
use App\Services\AvatarService;

class ProfileController extends Controller
{
    /**
     * 个人中心页
     */
    public function index(): void
    {
        $user = Auth::user();
        if ($user === null) {
            Session::flash('error', '请先登录后再访问');
            $this->redirect('login');
            return;
        }

        $this->view('profile.index', [
            'user'       => $user,
            'flash'      => Session::takeFlash(),
            'workCount'  => Work::countByUser((int) $user['id']),
            'recentWorks' => array_slice(Work::listByUser((int) $user['id']), 0, 5),
        ]);
    }

    /**
     * 更新资料
     */
    public function update(): void
    {
        if (Auth::id() === null) {
            $this->redirect('login');
            return;
        }
        $this->verifyCsrf();

        $result = Auth::updateProfile([
            'nickname' => $this->input('nickname', ''),
            'bio'      => $this->input('bio', ''),
        ]);

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('profile');
    }

    /**
     * 上传头像
     */
    public function avatar(): void
    {
        $userId = Auth::id();
        if ($userId === null) {
            $this->redirect('login');
            return;
        }
        $this->verifyCsrf();

        $result = AvatarService::save($userId, $_FILES['avatar'] ?? [], (string) $this->input('license', 'original'));
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('profile');
    }

    /**
     * 移除头像，恢复默认首字母头像
     */
    public function avatarDelete(): void
    {
        $userId = Auth::id();
        if ($userId === null) {
            $this->redirect('login');
            return;
        }
        $this->verifyCsrf();

        $result = AvatarService::remove($userId);
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('profile');
    }

    /**
     * 修改密码
     */
    public function password(): void
    {
        if (Auth::id() === null) {
            $this->redirect('login');
            return;
        }
        $this->verifyCsrf();

        $result = Auth::changePassword(
            (string) $this->input('current_password', ''),
            (string) $this->input('new_password', ''),
            (string) $this->input('new_password_confirm', '')
        );

        if (!$result['ok']) {
            Session::flash('error', $result['message']);
            $this->redirect('profile');
            return;
        }

        // 密码已变更，令牌全部失效，需要重新登录
        Auth::logout();
        Session::flash('success', $result['message']);
        $this->redirect('login');
    }

    /**
     * 注销账号
     */
    public function destroy(): void
    {
        if (Auth::id() === null) {
            $this->redirect('login');
            return;
        }
        $this->verifyCsrf();

        $result = Auth::deleteAccount((string) $this->input('password', ''));
        if (!$result['ok']) {
            Session::flash('error', $result['message']);
            $this->redirect('profile');
            return;
        }

        Session::flash('success', $result['message']);
        $this->redirect('/');
    }
}
