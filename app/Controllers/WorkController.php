<?php
/**
 * 作品控制器
 *
 * 提供作品列表页与供编辑器调用的 JSON 接口（保存 / 读取 / 删除）。
 * 所有接口均按 user_id 鉴权，防止越权访问他人作品。
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\DB;
use App\Core\Session;
use App\Models\Comment;
use App\Models\Favorite;
use App\Models\Like;
use App\Models\Report;
use App\Models\Work;
use App\Models\WorkRevision;
use App\Services\Auth;
use App\Services\AvatarService;

class WorkController extends Controller
{
    /** 单份作品 JSON 体积上限（字节），约 5MB */
    private const MAX_DATA_BYTES = 5242880;

    /** 每份作品保留的历史快照条数 */
    private const MAX_REVISIONS = 30;

    /**
     * 我的作品列表页
     */
    public function index(): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }

        $page = max(1, (int) $this->query('page', 1));
        $result = Work::paginateByUser($userId, $page);

        $this->view('work.index', [
            'user'   => Auth::user(),
            'flash'  => Session::takeFlash(),
            'works'  => $result['items'],
            'pager'  => $result,
        ]);
    }

    /**
     * 作品广场（公开作品列表，无需登录）
     *
     * 支持关键词搜索、标签筛选与最新/最热排序。
     */
    public function square(): void
    {
        $page = max(1, (int) $this->query('page', 1));
        $keyword = (string) $this->query('q', '');
        $tag = (string) $this->query('tag', '');
        $sort = (string) $this->query('sort', 'new');
        if (!in_array($sort, ['new', 'hot'], true)) {
            $sort = 'new';
        }

        $result = Work::paginatePublic($page, 12, [
            'keyword' => $keyword,
            'tag'     => $tag,
            'sort'    => $sort,
        ]);

        $this->view('work.square', [
            'user'    => Auth::user(),
            'flash'   => Session::takeFlash(),
            'works'   => $result['items'],
            'pager'   => $result,
            'tags'    => Work::listPublicTags(20),
            'keyword' => $keyword,
            'tag'     => $tag,
            'sort'    => $sort,
        ]);
    }

    /**
     * 作品详情页（短链 /w/{code}，无需登录）
     *
     * 输出 OG 标签，便于分享到社交平台时展示标题与简介。
     */
    public function detail(string $code): void
    {
        $work = Work::findPublicDetail($code);
        if ($work === null) {
            http_response_code(404);
            $this->view('work.detail', [
                'user'          => Auth::user(),
                'work'          => null,
                'code'          => $code,
                'liked'         => false,
                'favorited'     => false,
                'favoriteTotal' => 0,
                'commentTotal'  => 0,
            ]);
            return;
        }

        $userId = Auth::id();
        $workId = (int) $work['id'];
        $this->view('work.detail', [
            'user'          => Auth::user(),
            'work'          => $work,
            'code'          => $code,
            'liked'         => $userId !== null && Like::existsBy($workId, $userId),
            'favorited'     => $userId !== null && Favorite::existsBy($workId, $userId),
            'favoriteTotal' => Favorite::countByWork($workId),
            'commentTotal'  => Comment::countByWork($workId),
        ]);
    }

    /**
     * 我的收藏（需登录）
     */
    public function favorites(): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }

        $page = max(1, (int) $this->query('page', 1));
        $result = Favorite::paginateByUser($userId, $page);

        $this->view('work.favorites', [
            'user'  => Auth::user(),
            'flash' => Session::takeFlash(),
            'works' => $result['items'],
            'pager' => $result,
        ]);
    }

    /**
     * 精简嵌入播放页（/embed/{code}，供 iframe 引用）
     */
    public function embed(string $code): void
    {
        $work = Work::findByShortCode($code);
        if ($work === null) {
            http_response_code(404);
            $this->view('work.embed', ['work' => null, 'code' => $code]);
            return;
        }

        $this->view('work.embed', ['work' => $work, 'code' => $code]);
    }

    /**
     * 点赞 / 取消点赞（需登录）
     *
     * POST /api/works/{id}/like
     * 参数：action=like 点赞，action=unlike 取消
     */
    public function like(string $id): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }
        $this->verifyCsrf();

        $workId = (int) $id;
        if (Work::findPublic($workId) === null) {
            $this->json(['ok' => false, 'message' => '作品不存在或未发布'], 404);
            return;
        }

        $action = (string) $this->input('action', 'like');
        if ($action === 'unlike') {
            Like::remove($workId, $userId);
        } else {
            Like::add($workId, $userId);
        }

        $count = Work::syncLikeCount($workId);
        $this->json([
            'ok'         => true,
            'liked'      => $action !== 'unlike',
            'like_count' => $count,
        ]);
    }

    /**
     * 收藏 / 取消收藏（需登录）
     *
     * POST /api/works/{id}/favorite
     * 参数：action=favorite 收藏，action=unfavorite 取消
     */
    public function favorite(string $id): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }
        $this->verifyCsrf();

        $workId = (int) $id;
        if (Work::findPublic($workId) === null) {
            $this->json(['ok' => false, 'message' => '作品不存在或未发布'], 404);
            return;
        }

        $action = (string) $this->input('action', 'favorite');
        if ($action === 'unfavorite') {
            Favorite::remove($workId, $userId);
        } else {
            Favorite::add($workId, $userId);
        }

        $this->json([
            'ok'             => true,
            'favorited'      => $action !== 'unfavorite',
            'favorite_count' => Favorite::countByWork($workId),
        ]);
    }

    /**
     * 举报作品或评论（需登录）
     *
     * POST /api/reports
     * 参数：target_type=work|comment、target_id、reason、detail（选填）
     */
    public function report(): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }
        $this->verifyCsrf();

        $targetType = (string) $this->input('target_type', '');
        $targetId = (int) $this->input('target_id', 0);
        $reason = (string) $this->input('reason', '');
        $detail = trim((string) $this->input('detail', ''));

        if (!in_array($targetType, [Report::TARGET_WORK, Report::TARGET_COMMENT], true) || $targetId <= 0) {
            $this->json(['ok' => false, 'message' => '举报对象不正确'], 422);
            return;
        }
        if (!Report::isValidReason($reason)) {
            $this->json(['ok' => false, 'message' => '请选择举报原因'], 422);
            return;
        }
        if (mb_strlen($detail) > Report::MAX_DETAIL_LENGTH) {
            $this->json(['ok' => false, 'message' => '补充说明最多 ' . Report::MAX_DETAIL_LENGTH . ' 字'], 422);
            return;
        }

        // 校验举报对象真实存在，避免写入无效数据
        if ($targetType === Report::TARGET_WORK) {
            if (Work::findPublic($targetId) === null) {
                $this->json(['ok' => false, 'message' => '作品不存在或未发布'], 404);
                return;
            }
        } elseif (Comment::findVisible($targetId) === null) {
            $this->json(['ok' => false, 'message' => '评论不存在'], 404);
            return;
        }

        $isNew = Report::submit($targetType, $targetId, $userId, $reason, $detail);
        $this->json([
            'ok'      => true,
            'message' => $isNew ? '举报已提交，我们会尽快处理' : '举报内容已更新',
        ]);
    }

    /**
     * 记录一次播放（无需登录，同一会话内同一作品只计一次）
     *
     * POST /api/works/{id}/play
     */
    public function play(string $id): void
    {
        $workId = (int) $id;
        if (Work::findPublic($workId) === null) {
            $this->json(['ok' => false, 'message' => '作品不存在或未发布'], 404);
            return;
        }

        // 会话级去重：刷新页面不重复计数
        $seen = Session::get('_played_works', []);
        if (!is_array($seen)) {
            $seen = [];
        }
        if (!in_array($workId, $seen, true)) {
            Work::incrementPlayCount($workId);
            $seen[] = $workId;
            // 只保留最近 200 条，避免会话数据无限增长
            if (count($seen) > 200) {
                $seen = array_slice($seen, -200);
            }
            Session::set('_played_works', $seen);
        }

        $this->json(['ok' => true]);
    }

    /**
     * 读取公开作品详情（无需登录，仅限已发布作品）
     *
     * GET /api/works/{id}/public
     */
    public function showPublic(string $id): void
    {
        $work = Work::findPublic((int) $id);
        if ($work === null) {
            $this->json(['ok' => false, 'message' => '作品不存在或未发布'], 404);
            return;
        }

        $this->json([
            'ok'   => true,
            'work' => [
                'id'         => (int) $work['id'],
                'title'      => $work['title'],
                'updated_at' => $work['updated_at'],
                'data'       => json_decode((string) $work['data'], true),
            ],
        ]);
    }

    /**
     * 保存作品（新建或更新）
     *
     * POST /api/works/save
     * 参数：id（可选，为空则新建）、title、data（JSON 字符串）、description、is_public
     */
    public function save(): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }
        $this->verifyCsrf();

        $id = (int) $this->input('id', 0);
        $title = trim((string) $this->input('title', ''));
        $description = trim((string) $this->input('description', ''));
        $rawData = (string) $this->input('data', '');

        if ($title === '') {
            $title = '未命名作品';
        }
        if (mb_strlen($title) > 120) {
            $this->json(['ok' => false, 'message' => '作品名称不能超过 120 个字符'], 422);
            return;
        }
        if (mb_strlen($description) > 500) {
            $this->json(['ok' => false, 'message' => '作品简介不能超过 500 个字符'], 422);
            return;
        }
        if ($rawData === '') {
            $this->json(['ok' => false, 'message' => '作品数据为空'], 422);
            return;
        }
        if (strlen($rawData) > self::MAX_DATA_BYTES) {
            $this->json(['ok' => false, 'message' => '作品数据过大，无法保存'], 413);
            return;
        }

        $decoded = json_decode($rawData, true);
        if (!is_array($decoded) || !isset($decoded['manifest'], $decoded['scenes'])) {
            $this->json(['ok' => false, 'message' => '作品数据格式不正确'], 422);
            return;
        }

        $scenes = is_array($decoded['scenes']) ? $decoded['scenes'] : [];
        $payload = [
            'title'       => $title,
            'description' => $description,
            'data'        => $rawData,
            'scene_count' => count($scenes),
            'word_count'  => $this->countWords($scenes),
            'is_public'   => $this->input('is_public') ? 1 : 0,
            'tags'        => Work::normalizeTags((string) $this->input('tags', '')),
        ];

        if ($id > 0) {
            // 更新前先确认归属，避免越权改写他人作品
            $existing = Work::findOwned($id, $userId);
            if ($existing === null) {
                $this->json(['ok' => false, 'message' => '作品不存在或无权访问'], 404);
                return;
            }
            // 覆盖前先留存旧版本快照，便于回溯
            if ((string) $existing['data'] !== '') {
                WorkRevision::create([
                    'work_id' => $id,
                    'user_id' => $userId,
                    'data'    => (string) $existing['data'],
                    'remark'  => '保存前自动快照',
                ]);
                WorkRevision::prune($id, self::MAX_REVISIONS);
            }
            Work::updateById($id, $payload);
        } else {
            $payload['user_id'] = $userId;
            $id = Work::create($payload);
        }

        $this->json([
            'ok'       => true,
            'message'  => '已保存到云端',
            'id'       => $id,
            'saved_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 读取作品详情
     *
     * GET /api/works/{id}
     */
    public function show(string $id): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }

        $work = Work::findOwned((int) $id, $userId);
        if ($work === null) {
            $this->json(['ok' => false, 'message' => '作品不存在或无权访问'], 404);
            return;
        }

        $this->json([
            'ok'   => true,
            'work' => [
                'id'          => (int) $work['id'],
                'title'       => $work['title'],
                'description' => $work['description'],
                'is_public'   => (int) $work['is_public'],
                'updated_at'  => $work['updated_at'],
                'data'        => json_decode((string) $work['data'], true),
            ],
        ]);
    }

    /**
     * 删除作品（软删）
     *
     * POST /api/works/{id}/delete
     */
    public function delete(string $id): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }
        $this->verifyCsrf();

        $affected = Work::softDelete((int) $id, $userId);
        if ($affected === 0) {
            $this->json(['ok' => false, 'message' => '作品不存在或无权访问'], 404);
            return;
        }

        $this->json(['ok' => true, 'message' => '作品已删除']);
    }

    /**
     * 重命名作品
     *
     * POST /api/works/{id}/rename
     */
    public function rename(string $id): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }
        $this->verifyCsrf();

        $title = trim((string) $this->input('title', ''));
        if ($title === '') {
            $this->json(['ok' => false, 'message' => '作品名称不能为空'], 422);
            return;
        }
        if (mb_strlen($title) > 120) {
            $this->json(['ok' => false, 'message' => '作品名称不能超过 120 个字符'], 422);
            return;
        }

        if (Work::findOwned((int) $id, $userId) === null) {
            $this->json(['ok' => false, 'message' => '作品不存在或无权访问'], 404);
            return;
        }

        Work::updateById((int) $id, ['title' => $title]);
        $this->json(['ok' => true, 'message' => '已重命名', 'title' => $title]);
    }

    /**
     * 发布 / 取消发布作品
     *
     * POST /api/works/{id}/publish
     * 参数：public=1 发布，public=0 取消发布
     */
    public function publish(string $id): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }
        $this->verifyCsrf();

        $public = (int) $this->input('public', 1) === 1;
        $result = Work::setPublic((int) $id, $userId, $public);
        if (!$result['ok']) {
            $this->json(['ok' => false, 'message' => '作品不存在或无权访问'], 404);
            return;
        }

        $this->json([
            'ok'         => true,
            'message'    => $public ? '作品已发布' : '已取消发布',
            'is_public'  => $public ? 1 : 0,
            'short_code' => $result['short_code'],
            'share_url'  => $result['short_code'] !== '' ? base_url('w/' . $result['short_code']) : '',
        ]);
    }

    /**
     * 历史版本列表
     *
     * GET /api/works/{id}/revisions
     */
    public function revisions(string $id): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }

        $workId = (int) $id;
        if (Work::findOwned($workId, $userId) === null) {
            $this->json(['ok' => false, 'message' => '作品不存在或无权访问'], 404);
            return;
        }

        $items = [];
        foreach (WorkRevision::listByWork($workId) as $row) {
            $items[] = [
                'id'         => (int) $row['id'],
                'remark'     => $row['remark'],
                'created_at' => $row['created_at'],
            ];
        }

        $this->json(['ok' => true, 'revisions' => $items]);
    }

    /**
     * 恢复指定历史版本
     *
     * POST /api/works/{id}/revisions/{revisionId}/restore
     */
    public function restoreRevision(string $id, string $revisionId): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }
        $this->verifyCsrf();

        $workId = (int) $id;
        $work = Work::findOwned($workId, $userId);
        if ($work === null) {
            $this->json(['ok' => false, 'message' => '作品不存在或无权访问'], 404);
            return;
        }

        $revision = WorkRevision::findOwned((int) $revisionId, $workId);
        if ($revision === null || (string) $revision['data'] === '') {
            $this->json(['ok' => false, 'message' => '历史版本不存在'], 404);
            return;
        }

        $decoded = json_decode((string) $revision['data'], true);
        if (!is_array($decoded) || !isset($decoded['manifest'], $decoded['scenes'])) {
            $this->json(['ok' => false, 'message' => '历史版本数据已损坏'], 422);
            return;
        }

        // 恢复前把当前版本也存为快照，避免恢复操作不可逆
        if ((string) $work['data'] !== '') {
            WorkRevision::create([
                'work_id' => $workId,
                'user_id' => $userId,
                'data'    => (string) $work['data'],
                'remark'  => '恢复前自动快照',
            ]);
            WorkRevision::prune($workId, self::MAX_REVISIONS);
        }

        $scenes = is_array($decoded['scenes']) ? $decoded['scenes'] : [];
        Work::updateById($workId, [
            'data'        => (string) $revision['data'],
            'scene_count' => count($scenes),
            'word_count'  => $this->countWords($scenes),
        ]);

        $this->json([
            'ok'      => true,
            'message' => '已恢复到该历史版本',
            'data'    => $decoded,
        ]);
    }

    /**
     * 评论列表（无需登录）
     *
     * GET /api/works/{id}/comments?page=1
     */
    public function comments(string $id): void
    {
        $workId = (int) $id;
        $work = Work::findPublic($workId);
        if ($work === null) {
            $this->json(['ok' => false, 'message' => '作品不存在或未发布'], 404);
            return;
        }

        $page = max(1, (int) $this->query('page', 1));
        $result = Comment::paginateByWork($workId, $page);

        $userId = Auth::id();
        $workAuthorId = (int) $work['user_id'];
        $items = [];
        foreach ($result['items'] as $row) {
            $items[] = $this->formatComment($row, $userId, $workAuthorId);
        }

        $this->json([
            'ok'    => true,
            'items' => $items,
            'total' => $result['total'],
            'page'  => $result['page'],
            'pages' => $result['pages'],
        ]);
    }

    /**
     * 发表评论（需登录）
     *
     * POST /api/works/{id}/comments
     * 参数：content
     */
    public function commentStore(string $id): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }
        $this->verifyCsrf();

        $workId = (int) $id;
        $work = Work::findPublic($workId);
        if ($work === null) {
            $this->json(['ok' => false, 'message' => '作品不存在或未发布'], 404);
            return;
        }

        $content = (string) $this->input('content', '');
        if ($content === '') {
            $this->json(['ok' => false, 'message' => '评论内容不能为空'], 422);
            return;
        }
        if (mb_strlen($content) > Comment::MAX_LENGTH) {
            $this->json(['ok' => false, 'message' => '评论最多 ' . Comment::MAX_LENGTH . ' 字'], 422);
            return;
        }

        // 发表限频：同一用户 60 秒内最多 5 条，防止刷屏
        if (!$this->allowComment($userId)) {
            $this->json(['ok' => false, 'message' => '评论过于频繁，请稍后再试'], 429);
            return;
        }

        $commentId = Comment::create([
            'work_id' => $workId,
            'user_id' => $userId,
            'content' => $content,
            'status'  => 1,
        ]);

        $row = Comment::findVisible($commentId);
        $this->json([
            'ok'      => true,
            'message' => '评论已发表',
            'comment' => $row !== null ? $this->formatComment($row, $userId, (int) $work['user_id']) : null,
            'total'   => Comment::countByWork($workId),
        ]);
    }

    /**
     * 删除评论（需登录，仅作者本人或作品作者可删）
     *
     * POST /api/comments/{id}/delete
     */
    public function commentDelete(string $id): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }
        $this->verifyCsrf();

        $comment = Comment::findVisible((int) $id);
        if ($comment === null) {
            $this->json(['ok' => false, 'message' => '评论不存在'], 404);
            return;
        }

        $workId = (int) $comment['work_id'];
        $work = Work::find($workId);
        $isOwner = (int) $comment['user_id'] === $userId;
        $isWorkAuthor = $work !== null && (int) $work['user_id'] === $userId;
        if (!$isOwner && !$isWorkAuthor) {
            $this->json(['ok' => false, 'message' => '无权删除该评论'], 403);
            return;
        }

        Comment::softDelete((int) $comment['id']);
        $this->json([
            'ok'      => true,
            'message' => '评论已删除',
            'total'   => Comment::countByWork($workId),
        ]);
    }

    /**
     * 评论限频：同一用户 60 秒内最多 5 条
     */
    private function allowComment(int $userId): bool
    {
        $count = (int) DB::value(
            'SELECT COUNT(*) FROM `comments`
             WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)',
            [$userId]
        );
        return $count < 5;
    }

    /**
     * 整理评论输出结构（作者展示名、头像、是否可删）
     *
     * @param int|null $viewerId     当前登录用户
     * @param int      $workAuthorId 作品作者，可删除其作品下的任意评论
     */
    private function formatComment(array $row, ?int $viewerId, int $workAuthorId = 0): array
    {
        $nickname = (string) ($row['author_nickname'] ?? '');
        $email = (string) ($row['author_email'] ?? '');
        $authorId = (int) $row['user_id'];

        return [
            'id'         => (int) $row['id'],
            'content'    => (string) $row['content'],
            'created_at' => (string) $row['created_at'],
            'author'     => $nickname !== '' ? $nickname : $email,
            'avatar'     => AvatarService::url($row['author_avatar'] ?? ''),
            'is_author'  => $viewerId !== null && $authorId === $viewerId,
            'can_delete' => $viewerId !== null
                && ($authorId === $viewerId || ($workAuthorId > 0 && $viewerId === $workAuthorId)),
        ];
    }

    /**
     * 要求登录，未登录时按请求类型返回 JSON 或跳转
     *
     * @return int|null 登录用户 ID
     */
    private function requireLogin(): ?int
    {
        $userId = Auth::id();
        if ($userId !== null) {
            return $userId;
        }

        if ($this->wantsJson()) {
            $this->json(['ok' => false, 'message' => '请先登录', 'need_login' => true], 401);
        } else {
            Session::flash('error', '请先登录后再访问');
            $this->redirect('login');
        }
        return null;
    }

    /**
     * 统计剧本字数（仅统计对话与选项文本）
     */
    private function countWords(array $scenes): int
    {
        $count = 0;
        foreach ($scenes as $scene) {
            $nodes = is_array($scene) ? ($scene['nodes'] ?? []) : [];
            if (!is_array($nodes)) {
                continue;
            }
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $type = $node['type'] ?? '';
                if ($type === 'say') {
                    $count += mb_strlen(trim((string) ($node['text'] ?? '')));
                } elseif ($type === 'choose') {
                    foreach ((array) ($node['options'] ?? []) as $option) {
                        $count += mb_strlen(trim((string) ($option['text'] ?? '')));
                    }
                }
            }
        }
        return $count;
    }
}
