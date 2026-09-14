<?php
/**
 * 编辑器页面控制器
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Services\Auth;

class EditorController extends Controller
{
    /**
     * 编辑器页面
     */
    public function index(): void
    {
        $this->view('editor.index', [
            'user' => Auth::user(),
        ]);
    }
}
