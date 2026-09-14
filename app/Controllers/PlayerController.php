<?php
/**
 * 播放器页面控制器
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Services\Auth;

class PlayerController extends Controller
{
    /**
     * 播放器页面
     */
    public function index(): void
    {
        $this->view('player.index', [
            'user' => Auth::user(),
        ]);
    }
}
