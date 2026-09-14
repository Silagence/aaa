<?php
/**
 * 管理后台控制器
 *
 * 仅 role = admin 的用户可访问，所有入口统一走 requireAdmin() 鉴权。
 * 提供举报处理、作品管理、用户管理三块能力。
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Session;
use App\Models\Comment;
use App\Models\Report;
use App\Models\User;
use App\Models\Work;
use App\Services\Auth;

class AdminController extends Controller
{
    /** 列表每页条数 */
    private const PER_PAGE = 20;

    /**
     * 后台首页：待处理举报概览 + 站点统计
     */
    public function index(): void
    {
        if ($this->requireAdmin() === null) {
            return;
        }

        $this->view('admin/index', [
            'user'    => Auth::user(),
            'flash'   => Session::takeFlash(),
            'stats'   => [
                'pending'        => Report::countByStatus(Report::STATUS_PENDING),
                'resolved'       => Report::countByStatus(Report::STATUS_RESOLVED),
                'rejected'       => Report::countByStatus(Report::STATUS_REJECTED),
                'users'          => User::countAll(),
                'users_disabled' => User::countAll(0),
                'works'          => Work::countAll(),
                'works_public'   => Work::countAll(true),
            ],
            'latest'  => Report::paginateForAdmin(['status' => Report::STATUS_PENDING], 1, 5)['items'],
        ]);
    }

    /**
     * 举报列表页
     */
    public function reports(): void
    {
        if ($this->requireAdmin() === null) {
            return;
        }

        $status = $this->query('status', '');
        $targetType = (string) $this->query('target_type', '');

        $filters = [
            'status'      => $status === '' ? null : (int) $status,
            'target_type' => $targetType,
        ];

        $pager = Report::paginateForAdmin($filters, max(1, (int) $this->query('page', 1)), self::PER_PAGE);

        $this->view('admin/reports', [
            'user'       => Auth::user(),
            'flash'      => Session::takeFlash(),
            'reports'    => $pager['items'],
            'pager'      => $pager,
            'filters'    => ['status' => $status, 'target_type' => $targetType],
            'counts'     => [
                'all'      => Report::paginateForAdmin([], 1, 1)['total'],
                'pending'  => Report::countByStatus(Report::STATUS_PENDING),
                'resolved' => Report::countByStatus(Report::STATUS_RESOLVED),
                'rejected' => Report::countByStatus(Report::STATUS_REJECTED),
            ],
        ]);
    }

    /**
     * 处理举报（标记已处理 / 已驳回 / 恢复待处理）
     *
     * 可选同时下架被举报作品或隐藏被举报评论，一步完成处置。
     */
    public function handleReport(string $id): void
    {
        if ($this->requireAdmin() === null) {
            return;
        }
        $this->verifyCsrf();

        $reportId = (int) $id;
        $report = Report::findForAdmin($reportId);
        if ($report === null) {
            $this->json(['ok' => false, 'message' => '举报记录不存在'], 404);
            return;
        }

        $status = (int) $this->input('status', Report::STATUS_RESOLVED);
        if (!array_key_exists($status, Report::statuses())) {
            $this->json(['ok' => false, 'message' => '处理状态不正确'], 422);
            return;
        }

        Report::setStatus($reportId, $status);

        // 处置动作：仅在标记为"已处理"时执行，避免误操作
        $action = (string) $this->input('action', 'none');
        $actionDone = '';

        if ($status === Report::STATUS_RESOLVED && $action !== 'none') {
            $targetType = (string) $report['target_type'];
            $targetId = (int) $report['target_id'];

            if ($action === 'unpublish' && $targetType === Report::TARGET_WORK) {
                $actionDone = Work::forceUnpublish($targetId) ? '作品已下架' : '作品本就未发布';
            } elseif ($action === 'hide_comment' && $targetType === Report::TARGET_COMMENT) {
                $actionDone = Comment::softDelete($targetId) > 0 ? '评论已隐藏' : '评论已不存在';
            }
        }

        $this->json([
            'ok'      => true,
            'message' => '举报' . Report::statusLabel($status) . ($actionDone !== '' ? '，' . $actionDone : ''),
            'status'  => $status,
        ]);
    }

    /**
     * 作品管理列表页
     */
    public function works(): void
    {
        if ($this->requireAdmin() === null) {
            return;
        }

        $keyword = (string) $this->query('keyword', '');
        $status = $this->query('status', '');

        $pager = Work::paginateForAdmin(
            ['keyword' => $keyword, 'status' => $status === '' ? null : (int) $status],
            max(1, (int) $this->query('page', 1)),
            self::PER_PAGE
        );

        $this->view('admin/works', [
            'user'    => Auth::user(),
            'flash'   => Session::takeFlash(),
            'works'   => $pager['items'],
            'pager'   => $pager,
            'filters' => ['keyword' => $keyword, 'status' => $status],
        ]);
    }

    /**
     * 管理员下架 / 恢复作品
     */
    public function unpublishWork(string $id): void
    {
        if ($this->requireAdmin() === null) {
            return;
        }
        $this->verifyCsrf();

        $workId = (int) $id;
        $action = (string) $this->input('action', 'unpublish');

        if ($action === 'restore') {
            $ok = Work::setPublicByAdmin($workId, true);
            $this->json([
                'ok'      => $ok,
                'message' => $ok ? '作品已恢复发布' : '作品不存在或已删除',
            ], $ok ? 200 : 404);
            return;
        }

        $ok = Work::forceUnpublish($workId);
        $this->json([
            'ok'      => $ok,
            'message' => $ok ? '作品已下架' : '作品不存在或本就未发布',
        ], $ok ? 200 : 404);
    }

    /**
     * 用户管理列表页
     */
    public function users(): void
    {
        if ($this->requireAdmin() === null) {
            return;
        }

        $keyword = (string) $this->query('keyword', '');
        $role = (string) $this->query('role', '');
        $status = $this->query('status', '');

        $pager = User::paginateForAdmin(
            [
                'keyword' => $keyword,
                'role'    => $role,
                'status'  => $status === '' ? null : (int) $status,
            ],
            max(1, (int) $this->query('page', 1)),
            self::PER_PAGE
        );

        $this->view('admin/users', [
            'user'    => Auth::user(),
            'flash'   => Session::takeFlash(),
            'users'   => $pager['items'],
            'pager'   => $pager,
            'filters' => ['keyword' => $keyword, 'role' => $role, 'status' => $status],
        ]);
    }

    /**
     * 启用 / 禁用用户
     */
    public function toggleUser(string $id): void
    {
        $adminId = $this->requireAdmin();
        if ($adminId === null) {
            return;
        }
        $this->verifyCsrf();

        $userId = (int) $id;
        if ($userId === $adminId) {
            $this->json(['ok' => false, 'message' => '不能禁用当前登录的管理员账号'], 422);
            return;
        }

        $target = User::find($userId);
        if ($target === null) {
            $this->json(['ok' => false, 'message' => '用户不存在'], 404);
            return;
        }

        $status = (int) $this->input('status', 0) === 1 ? 1 : 0;
        User::setStatus($userId, $status);

        $this->json([
            'ok'      => true,
            'message' => $status === 1 ? '账号已启用' : '账号已禁用',
            'status'  => $status,
        ]);
    }

    /**
     * 要求管理员身份，未通过时按请求类型返回 JSON 或跳转
     *
     * @return int|null 管理员用户 ID
     */
    private function requireAdmin(): ?int
    {
        $userId = Auth::id();
        if ($userId !== null && Auth::isAdmin()) {
            return $userId;
        }

        if ($this->wantsJson()) {
            $this->json(['ok' => false, 'message' => '无权访问'], 403);
        } else {
            Session::flash('error', $userId === null ? '请先登录后再访问' : '无权访问管理后台');
            $this->redirect($userId === null ? 'login' : 'works');
        }
        return null;
    }
}
