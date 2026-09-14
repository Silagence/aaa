<?php
/**
 * 静态内容页控制器
 *
 * 提供「使用手册」与「关于网站」两个公开页面，无需登录即可访问。
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Session;
use App\Services\Auth;

class PageController extends Controller
{
    /**
     * 使用手册
     */
    public function manual(): void
    {
        $this->view('manual/index', [
            'user'  => Auth::user(),
            'flash' => Session::takeFlash(),
        ]);
    }

    /**
     * 关于网站
     */
    public function about(): void
    {
        $this->view('about/index', [
            'user'  => Auth::user(),
            'flash' => Session::takeFlash(),
        ]);
    }
}
