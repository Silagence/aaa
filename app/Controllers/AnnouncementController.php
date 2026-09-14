<?php
/**
 * 网站公告控制器（面向普通用户）
 *
 * 仅展示已发布（status = 1）的公告，置顶优先、最新在前。
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Session;
use App\Models\Announcement;
use App\Services\Auth;

class AnnouncementController extends Controller
{
    /**
     * 公告列表页（需登录）
     */
    public function index(): void
    {
        $userId = Auth::id();
        if ($userId === null) {
            Session::flash('error', '请先登录后再访问');
            $this->redirect('login');
            return;
        }

        $this->view('announcement/index', [
            'user'          => Auth::user(),
            'flash'         => Session::takeFlash(),
            'announcements' => Announcement::published(),
        ]);
    }
}
