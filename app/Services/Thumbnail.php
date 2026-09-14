<?php
/**
 * 缩略图生成服务
 *
 * 上传图片素材时按需生成一张小尺寸缩略图，编辑器素材库优先加载缩略图，
 * 避免为每张卡片下载数 MB 的原图（需求 5 性能指标：编辑器加载 ≤ 3s）。
 *
 * 约定：
 * - 仅处理图片（bg / sprite），音频素材不生成缩略图
 * - 等比缩放到不超过 maxWidth × maxHeight 的框内，不放大
 * - 统一输出 JPEG（质量 82），透明区域铺白底，避免 PNG 透明通道变黑
 * - 生成失败返回 null，调用方回退为使用原图，不影响上传主流程
 */

declare(strict_types=1);

namespace App\Services;

class Thumbnail
{
    /** 缩略图最大边长（像素） */
    private const MAX_WIDTH = 320;
    private const MAX_HEIGHT = 320;

    /** JPEG 输出质量 */
    private const QUALITY = 82;

    /**
     * 生成缩略图文件
     *
     * @param string $sourcePath 原图绝对路径
     * @param string $targetPath 缩略图输出绝对路径（.jpg）
     * @param int    $width      原图宽
     * @param int    $height     原图高
     * @param string $mime       原图 MIME
     * @return bool 是否生成成功
     */
    public static function generate(string $sourcePath, string $targetPath, int $width, int $height, string $mime): bool
    {
        if (!function_exists('imagecreatetruecolor') || $width <= 0 || $height <= 0) {
            return false;
        }

        $source = self::createImage($sourcePath, $mime);
        if ($source === null) {
            return false;
        }

        // 等比缩放到框内，且不放大（原图比框小时保持原尺寸）
        $scale = min(self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height, 1.0);
        $targetW = max(1, (int) round($width * $scale));
        $targetH = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetW, $targetH);
        if ($canvas === false) {
            return false;
        }

        // 铺白底：JPEG 不支持透明通道，透明像素会变黑
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $white);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);

        $dir = dirname($targetPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $ok = imagejpeg($canvas, $targetPath, self::QUALITY);
        if ($ok) {
            @chmod($targetPath, 0644);
        }

        return (bool) $ok;
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
}
