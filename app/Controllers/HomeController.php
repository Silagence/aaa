<?php
/**
 * 首页控制器
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Services\Auth;

class HomeController extends Controller
{
    /**
     * 首页
     */
    public function index(): void
    {
        $this->view('home.index', [
            'user'  => Auth::user(),
            'flash' => \App\Core\Session::takeFlash(),
        ]);
    }
}
