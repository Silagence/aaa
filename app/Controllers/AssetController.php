<?php
/**
 * 用户素材控制器
 *
 * 提供素材上传 / 列表 / 删除 / 修改可见性接口。
 * 所有接口均需登录，并按 user_id 鉴权，防止越权访问他人素材。
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Session;
use App\Models\Asset;
use App\Models\Work;
use App\Services\Auth;

class AssetController extends Controller
{
    /**
     * 素材列表
     *
     * GET /api/assets
     * 返回当前用户的全部素材，按编辑器素材库分组键归类。
     */
    public function index(): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }

        $grouped = ['backgrounds' => [], 'sprites' => [], 'bgm' => [], 'sfx' => []];
        foreach (Asset::listByUser($userId) as $row) {
            $group = Asset::TYPE_MAP[$row['type']] ?? null;
            if ($group === null) {
                continue;
            }
            $grouped[$group][] = $this->present($row, $userId);
        }

        $usage = Asset::usage($userId);
        $this->json([
            'ok'      => true,
            'assets'  => $grouped,
            'usage'   => [
                'bytes'      => $usage['bytes'],
                'count'      => $usage['count'],
                'quota_bytes' => (int) config('upload.quota_user', 0),
                'quota_count' => (int) config('upload.quota_count', 0),
            ],
            'licenses' => config('upload.licenses', []),
        ]);
    }

    /**
     * 上传素材
     *
     * POST /api/assets
     * 参数：file（multipart）、type（bg/sprite/bgm/sfx）、visibility、license
     */
    public function store(): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }
        $this->verifyCsrf();

        $type = (string) $this->input('type', '');
        if (!isset(Asset::TYPE_MAP[$type])) {
            $this->json(['ok' => false, 'message' => '素材类型不正确'], 422);
            return;
        }

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || !isset($file['error'])) {
            $this->json(['ok' => false, 'message' => '未接收到上传文件'], 422);
            return;
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            $this->json(['ok' => false, 'message' => $this->uploadErrorMessage((int) $file['error'])], 422);
            return;
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            $this->json(['ok' => false, 'message' => '上传文件无效'], 422);
            return;
        }

        $size = (int) ($file['size'] ?? 0);
        $maxSize = (int) config('upload.max_size', 0);
        if ($maxSize > 0 && $size > $maxSize) {
            $this->json(['ok' => false, 'message' => '文件超过 ' . $this->formatBytes($maxSize) . ' 限制'], 413);
            return;
        }
        if ($size <= 0) {
            $this->json(['ok' => false, 'message' => '文件内容为空'], 422);
            return;
        }

        // 扩展名白名单（不允许 svg）
        $originalName = (string) ($file['name'] ?? '');
        $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExt = (array) config('upload.allowed.' . $type, []);
        if ($ext === '' || !in_array($ext, $allowedExt, true)) {
            $this->json(['ok' => false, 'message' => '不支持的文件格式，仅允许：' . implode('、', $allowedExt)], 422);
            return;
        }

        // 真实 MIME 校验（不能只信扩展名）
        $mime = $this->detectMime($tmpPath);
        $allowedMime = (array) config('upload.mime.' . $type, []);
        if ($mime === '' || !in_array($mime, $allowedMime, true)) {
            $this->json(['ok' => false, 'message' => '文件内容与格式不符，已拒绝'], 422);
            return;
        }

        // 图片二次确认，并读取尺寸
        $width = 0;
        $height = 0;
        if ($type === 'bg' || $type === 'sprite') {
            $info = @getimagesize($tmpPath);
            if ($info === false || empty($info[0]) || empty($info[1])) {
                $this->json(['ok' => false, 'message' => '图片文件已损坏或格式不受支持'], 422);
                return;
            }
            $width = (int) $info[0];
            $height = (int) $info[1];
        }

        // 配额校验
        $usage = Asset::usage($userId);
        $quotaBytes = (int) config('upload.quota_user', 0);
        $quotaCount = (int) config('upload.quota_count', 0);
        if ($quotaCount > 0 && $usage['count'] >= $quotaCount) {
            $this->json(['ok' => false, 'message' => '素材数量已达上限（' . $quotaCount . ' 个）'], 422);
            return;
        }
        if ($quotaBytes > 0 && $usage['bytes'] + $size > $quotaBytes) {
            $this->json(['ok' => false, 'message' => '存储空间不足，剩余 ' . $this->formatBytes(max(0, $quotaBytes - $usage['bytes']))], 422);
            return;
        }

        // 版权协议（上传时由用户选择，非法值回退为原创）
        $license = (string) $this->input('license', 'original');
        if (!array_key_exists($license, (array) config('upload.licenses', []))) {
            $license = 'original';
        }

        // 内容哈希：同用户重复上传直接复用，不占额外空间
        $hash = hash_file('sha256', $tmpPath);
        if ($hash === false) {
            $this->json(['ok' => false, 'message' => '文件读取失败，请重试'], 500);
            return;
        }
        $existing = Asset::findByHash($userId, $hash);
        if ($existing !== null) {
            // 命中已软删的记录：恢复并更新元信息，同时把文件重新落盘
            if ((int) $existing['status'] !== 1) {
                $relative = (string) $existing['path'];
                $target = rtrim((string) config('upload.dir'), '/\\') . '/' . $relative;
                $dir = dirname($target);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    $this->json(['ok' => false, 'message' => '存储目录创建失败'], 500);
                    return;
                }
                if (!is_file($target) && !@move_uploaded_file($tmpPath, $target)) {
                    $this->json(['ok' => false, 'message' => '文件保存失败，请重试'], 500);
                    return;
                }
                @chmod($target, 0644);
                Asset::restore((int) $existing['id'], $userId, [
                    'name'       => $this->displayName($originalName),
                    'visibility' => (int) $this->input('visibility', 0) === 1 ? 1 : 0,
                    'license'    => $license,
                ]);
                $existing = Asset::findOwned((int) $existing['id'], $userId);
            }
            $this->json([
                'ok'      => true,
                'message' => '该素材已存在，已直接引用',
                'asset'   => $existing !== null ? $this->present($existing, $userId) : null,
                'dedup'   => true,
            ]);
            return;
        }

        // 落盘：storage/uploads/{userId}/{type}/{yyyyMM}/{hash}.{ext}
        $relative = $userId . '/' . $type . '/' . date('Ym') . '/' . substr($hash, 0, 16) . '.' . $ext;
        $target = rtrim((string) config('upload.dir'), '/\\') . '/' . $relative;
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->json(['ok' => false, 'message' => '存储目录创建失败'], 500);
            return;
        }
        if (!@move_uploaded_file($tmpPath, $target)) {
            $this->json(['ok' => false, 'message' => '文件保存失败，请重试'], 500);
            return;
        }
        @chmod($target, 0644);

        $id = Asset::create([
            'user_id'    => $userId,
            'type'       => $type,
            'name'       => $this->displayName($originalName),
            'path'       => $relative,
            'mime'       => $mime,
            'size'       => $size,
            'width'      => $width,
            'height'     => $height,
            'duration'   => 0,
            'hash'       => $hash,
            'visibility' => (int) $this->input('visibility', 0) === 1 ? 1 : 0,
            'license'    => $license,
            'status'     => 1,
        ]);

        $asset = Asset::findOwned($id, $userId);
        $this->json([
            'ok'      => true,
            'message' => '上传成功',
            'asset'   => $asset !== null ? $this->present($asset, $userId) : null,
        ]);
    }

    /**
     * 删除素材
     *
     * POST /api/assets/{id}/delete
     * 被作品引用时拒绝删除。
     */
    public function delete(string $id): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }
        $this->verifyCsrf();

        $assetId = (int) $id;
        $asset = Asset::findOwned($assetId, $userId);
        if ($asset === null) {
            $this->json(['ok' => false, 'message' => '素材不存在或无权访问'], 404);
            return;
        }

        // 引用保护：被任何作品引用时不允许删除
        $refId = Asset::refId($userId, $assetId);
        $refs = Asset::findReferencingWorks($userId, $refId);
        if ($refs !== []) {
            $titles = array_map(static fn(array $w): string => $w['title'], array_slice($refs, 0, 3));
            $more = count($refs) > 3 ? ' 等 ' . count($refs) . ' 个作品' : '';
            $this->json([
                'ok'      => false,
                'message' => '该素材正被《' . implode('》《', $titles) . '》' . $more . '引用，无法删除',
                'refs'    => $refs,
            ], 409);
            return;
        }

        Asset::softDelete($assetId, $userId);

        // 物理文件删除失败不影响业务结果（记录已软删，文件可后续清理）
        $path = rtrim((string) config('upload.dir'), '/\\') . '/' . (string) $asset['path'];
        if (is_file($path)) {
            @unlink($path);
        }

        $this->json(['ok' => true, 'message' => '素材已删除']);
    }

    /**
     * 修改素材可见性与版权协议
     *
     * POST /api/assets/{id}/meta
     * 参数：visibility（0 私人 / 1 公开）、license
     */
    public function meta(string $id): void
    {
        $userId = $this->requireLogin();
        if ($userId === null) {
            return;
        }
        $this->verifyCsrf();

        $assetId = (int) $id;
        if (Asset::findOwned($assetId, $userId) === null) {
            $this->json(['ok' => false, 'message' => '素材不存在或无权访问'], 404);
            return;
        }

        $data = [];
        if ($this->input('visibility') !== null) {
            $data['visibility'] = (int) $this->input('visibility', 0) === 1 ? 1 : 0;
        }
        $license = (string) $this->input('license', '');
        if ($license !== '') {
            if (!array_key_exists($license, (array) config('upload.licenses', []))) {
                $this->json(['ok' => false, 'message' => '版权协议不正确'], 422);
                return;
            }
            $data['license'] = $license;
        }

        Asset::updateMeta($assetId, $userId, $data);
        $asset = Asset::findOwned($assetId, $userId);
        $this->json([
            'ok'      => true,
            'message' => '已更新',
            'asset'   => $asset !== null ? $this->present($asset, $userId) : null,
        ]);
    }

    /**
     * 本地开发：输出上传素材文件
     *
     * GET /uploads/{path}
     * 生产环境由 Nginx 的 location /uploads/ 直接返回，不会走到这里；
     * 该路由仅用于 PHP 内置服务器（无法访问 public 之外的目录）。
     */
    public function serve(string $path): void
    {
        $root = rtrim((string) config('upload.dir'), '/\\');
        $target = realpath($root . '/' . $path);
        $base = realpath($root);

        // 防目录穿越：解析后的真实路径必须位于上传根目录内
        if ($base === false || $target === false || strncmp($target, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
            http_response_code(404);
            exit;
        }
        if (!is_file($target)) {
            http_response_code(404);
            exit;
        }

        $mime = $this->detectMime($target);
        header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($target));
        header('Cache-Control: public, max-age=604800');
        header('X-Content-Type-Options: nosniff');
        readfile($target);
        exit;
    }

    /**
     * 组装返回给前端的素材结构
     *
     * src/thumb 为相对 public 的完整路径（uploads/...），
     * 与内置素材（bg/x.png）区分，播放器据此解析。
     */
    private function present(array $row, int $userId): array
    {
        $url = (string) config('upload.url_prefix', 'uploads') . '/' . (string) $row['path'];
        return [
            'id'         => Asset::refId($userId, (int) $row['id']),
            'assetId'    => (int) $row['id'],
            'type'       => (string) $row['type'],
            'name'       => (string) $row['name'],
            'src'        => $url,
            'thumb'      => $url,
            'width'      => (int) $row['width'],
            'height'     => (int) $row['height'],
            'duration'   => (float) $row['duration'],
            'size'       => (int) $row['size'],
            'visibility' => (int) $row['visibility'],
            'license'    => (string) $row['license'],
            'created_at' => (string) $row['created_at'],
            'mine'       => true,
        ];
    }

    /**
     * 检测文件真实 MIME
     */
    private function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                if (is_string($mime) && $mime !== '') {
                    return strtolower($mime);
                }
            }
        }
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }
        return '';
    }

    /**
     * 由原始文件名生成展示名（去扩展名、限长）
     */
    private function displayName(string $originalName): string
    {
        $name = pathinfo($originalName, PATHINFO_FILENAME);
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');
        if ($name === '') {
            $name = '未命名素材';
        }
        return mb_substr($name, 0, 120);
    }

    /**
     * 上传错误码转提示文案
     */
    private function uploadErrorMessage(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return '文件超过服务器允许的大小';
            case UPLOAD_ERR_PARTIAL:
                return '文件上传不完整，请重试';
            case UPLOAD_ERR_NO_FILE:
                return '未选择文件';
            case UPLOAD_ERR_NO_TMP_DIR:
                return '服务器缺少临时目录';
            case UPLOAD_ERR_CANT_WRITE:
                return '服务器写入失败';
            case UPLOAD_ERR_EXTENSION:
                return '上传被服务器扩展中断';
            default:
                return '上传失败，请重试';
        }
    }

    /**
     * 字节数转可读文本
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . 'MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . 'KB';
        }
        return $bytes . 'B';
    }

    /**
     * 要求登录，未登录时按请求类型返回 JSON 或跳转
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
}
