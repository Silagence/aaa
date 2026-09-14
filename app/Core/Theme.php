<?php
/**
 * 主题管理
 *
 * 支持 4 种主题：dark（深色，默认）/ light（浅色）/ sepia（护眼）/ auto（跟随系统）。
 * 主题值保存在 cookie 中，服务端渲染时写入 <html data-theme>，避免首屏闪烁（FOUC）。
 */

declare(strict_types=1);

namespace App\Core;

class Theme
{
    public const COOKIE = 'dramatool_theme';

    /** 允许的主题值 */
    public const ALLOWED = ['dark', 'light', 'sepia', 'auto'];

    public const DEFAULT = 'dark';

    /**
     * 读取当前主题（来自 cookie，非法值回退默认）
     */
    public static function current(): string
    {
        $value = $_COOKIE[self::COOKIE] ?? '';
        return in_array($value, self::ALLOWED, true) ? $value : self::DEFAULT;
    }

    /**
     * 写入主题 cookie（一年有效，全站路径）
     */
    public static function set(string $theme): bool
    {
        if (!in_array($theme, self::ALLOWED, true)) {
            return false;
        }
        setcookie(self::COOKIE, $theme, [
            'expires'  => time() + 31536000,
            'path'     => '/',
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $theme;
        return true;
    }

    /**
     * 首屏防闪烁脚本：在 <head> 中同步执行，尽早写入 data-theme。
     * auto 时按系统偏好解析为 dark / light。
     */
    public static function foucScript(): string
    {
        return "(function(){try{"
            . "var m=document.cookie.match(/(?:^|; )" . self::COOKIE . "=([^;]*)/);"
            . "var t=m?decodeURIComponent(m[1]):'" . self::DEFAULT . "';"
            . "if(['dark','light','sepia','auto'].indexOf(t)<0)t='" . self::DEFAULT . "';"
            . "if(t==='auto'){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches?'light':'dark';}"
            . "document.documentElement.setAttribute('data-theme',t);"
            . "}catch(e){}})();";
    }
}
