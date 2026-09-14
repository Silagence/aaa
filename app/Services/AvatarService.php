<?php
/**
 * 头像服务
 *
 * 负责头像的上传校验、居中裁剪缩放、落盘与旧图清理。
 * 物理文件存放在 storage/uploads/avatars/{userId}/{hash}.{ext}，
 * 由 Nginx 映射到 /uploads/avatars/ 对外访问；数据库仅记录相对路径。
 */

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

class AvatarService
{
    /**
     * 保存用户头像
     *
     * @param array  $file    $_FILES 中的单个文件项
     * @param string $license 用户选择的版权协议，非法值回退为原创
     * @return array{ok: bool, message: string, avatar?: string}
     */
    public static function save(int $userId, array $file, string $license = 'original'): array
    {
        if (!isset($file['error'])) {
            return ['ok' => false, 'message' => '未接收到上传文件'];
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => self::uploadErrorMessage((int) $file['error'])];
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'message' => '上传文件无效'];
        }

        $size = (int) ($file['size'] ?? 0);
        $maxSize = (int) config('avatar.max_size', 0);
        if ($maxSize > 0 && $size > $maxSize) {
            return ['ok' => false, 'message' => '头像文件不能超过 ' . self::formatBytes($maxSize)];
        }
        if ($size <= 0) {
            return ['ok' => false, 'message' => '文件内容为空'];
        }

        // 扩展名白名单（不允许 svg）
        $ext = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowedExt = (array) config('avatar.allowed', []);
        if ($ext === '' || !in_array($ext, $allowedExt, true)) {
            return ['ok' => false, 'message' => '仅支持 ' . implode('、', $allowedExt) . ' 格式的图片'];
        }

        // 真实 MIME 校验（不能只信扩展名）
        $mime = self::detectMime($tmpPath);
        if ($mime === '' || !in_array($mime, (array) config('avatar.mime', []), true)) {
            return ['ok' => false, 'message' => '文件内容与格式不符，已拒绝'];
        }

        // 图片二次确认，并读取尺寸
        $info = @getimagesize($tmpPath);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            return ['ok' => false, 'message' => '图片文件已损坏或格式不受支持'];
        }

        // 统一裁剪缩放为正方形，同时剥离 EXIF 等元数据
        $square = self::makeSquare($tmpPath, (int) $info[0], (int) $info[1], $mime);
        if ($square === null) {
            return ['ok' => false, 'message' => '图片处理失败，请换一张图片重试'];
        }

        $dir = rtrim((string) config('avatar.dir'), '/\\') . '/' . $userId;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => '存储目录创建失败'];
        }

        // 文件名带内容哈希：内容变化即换名，避免浏览器缓存旧头像
        $relative = $userId . '/' . substr(hash('sha256', (string) $size . $mime . microtime(true)), 0, 16) . '.jpg';
        $target = rtrim((string) config('avatar.dir'), '/\\') . '/' . $relative;

        $saved = imagejpeg($square, $target, 88);
        if (!$saved) {
            return ['ok' => false, 'message' => '头像保存失败，请重试'];
        }
        @chmod($target, 0644);

        $old = (string) (User::find($userId)['avatar'] ?? '');
        User::updateById($userId, [
            'avatar'         => $relative,
            'avatar_license' => self::normalizeLicense($license),
        ]);
        self::removeFile($old);

        return ['ok' => true, 'message' => '头像已更新', 'avatar' => $relative];
    }

    /**
     * 删除用户头像，恢复为默认首字母头像
     *
     * @return array{ok: bool, message: string}
     */
    public static function remove(int $userId): array
    {
        $old = (string) (User::find($userId)['avatar'] ?? '');
        if ($old === '') {
            return ['ok' => false, 'message' => '当前没有自定义头像'];
        }

        User::updateById($userId, ['avatar' => '']);
        self::removeFile($old);

        return ['ok' => true, 'message' => '头像已移除'];
    }

    /**
     * 生成头像访问 URL，未设置时返回空串
     */
    public static function url(?string $avatar): string
    {
        $avatar = trim((string) $avatar);
        if ($avatar === '') {
            return '';
        }
        return base_url((string) config('avatar.url_prefix', 'uploads/avatars') . '/' . $avatar);
    }

    /**
     * 版权协议选项 {key: label}
     */
    public static function licenses(): array
    {
        return (array) config('avatar.licenses', []);
    }

    /**
     * 版权协议展示文案，未知值返回空串
     */
    public static function licenseLabel(?string $license): string
    {
        $license = trim((string) $license);
        return (string) (self::licenses()[$license] ?? '');
    }

    /**
     * 校验版权协议，非法值回退为原创
     */
    private static function normalizeLicense(string $license): string
    {
        return array_key_exists($license, self::licenses()) ? $license : 'original';
    }

    /**
     * 居中裁剪为正方形并缩放到配置尺寸
     *
     * @return \GdImage|null
     */
    private static function makeSquare(string $path, int $width, int $height, string $mime)
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $source = self::createImage($path, $mime);
        if ($source === null) {
            return null;
        }

        // 以短边为基准居中裁剪
        $side = min($width, $height);
        $srcX = (int) floor(($width - $side) / 2);
        $srcY = (int) floor(($height - $side) / 2);

        $target = (int) config('avatar.size', 256);
        $canvas = imagecreatetruecolor($target, $target);

        // 透明 PNG/GIF 先铺白底，避免缩放后出现黑块
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $target, $target, $white);

        imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, $target, $target, $side, $side);

        return $canvas;
    }

    /**
     * 按 MIME 创建 GD 图像资源
     *
     * @return \GdImage|null
     */
    private static function createImage(string $path, string $mime)
    {
        switch ($mime) {
            case 'image/png':
                $img = function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false;
                break;
            case 'image/jpeg':
                $img = function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false;
                break;
            case 'image/gif':
                $img = function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : false;
                break;
            case 'image/webp':
                $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
                break;
            default:
                $img = false;
        }
        return $img === false ? null : $img;
    }

    /**
     * 删除头像物理文件（失败不影响业务结果）
     */
    private static function removeFile(string $relative): void
    {
        if ($relative === '') {
            return;
        }
        $path = rtrim((string) config('avatar.dir'), '/\\') . '/' . $relative;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * 检测文件真实 MIME
     */
    private static function detectMime(string $path): string
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
     * 上传错误码转提示文案
     */
    private static function uploadErrorMessage(int $code): string
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
    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . 'MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . 'KB';
        }
        return $bytes . 'B';
    }
}
