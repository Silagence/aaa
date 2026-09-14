/**
 * 主题切换
 *
 * 主题值：dark / light / sepia / auto（跟随系统）
 * 存储：cookie（dramatool_theme），与服务端 Theme::current() 保持一致
 * 首屏防闪烁由 <head> 中的内联脚本完成，本文件负责运行期切换与系统偏好监听。
 */
(function () {
    'use strict';

    var COOKIE = 'dramatool_theme';
    var ALLOWED = ['dark', 'light', 'sepia', 'auto'];
    var DEFAULT = 'dark';
    var root = document.documentElement;
    var mql = window.matchMedia ? window.matchMedia('(prefers-color-scheme: light)') : null;

    /** 读取 cookie 中的主题偏好（原始值，可能是 auto） */
    function readPref() {
        var m = document.cookie.match(new RegExp('(?:^|; )' + COOKIE + '=([^;]*)'));
        var v = m ? decodeURIComponent(m[1]) : DEFAULT;
        return ALLOWED.indexOf(v) >= 0 ? v : DEFAULT;
    }

    /** 写入 cookie */
    function writePref(v) {
        document.cookie = COOKIE + '=' + encodeURIComponent(v) + ';path=/;max-age=31536000;samesite=Lax';
    }

    /** 把偏好解析为实际生效的主题（auto → 按系统） */
    function resolve(pref) {
        if (pref === 'auto') {
            return mql && mql.matches ? 'light' : 'dark';
        }
        return pref;
    }

    /** 应用主题到根元素 */
    function apply(pref) {
        root.setAttribute('data-theme', resolve(pref));
        root.setAttribute('data-theme-pref', pref);
    }

    var Theme = {
        /** 当前偏好（dark/light/sepia/auto） */
        get: function () {
            return readPref();
        },

        /** 当前实际生效的主题（dark/light/sepia） */
        resolved: function () {
            return resolve(readPref());
        },

        /** 设置主题并持久化 */
        set: function (pref) {
            if (ALLOWED.indexOf(pref) < 0) return;
            writePref(pref);
            apply(pref);
            document.dispatchEvent(new CustomEvent('themechange', {
                detail: { pref: pref, theme: resolve(pref) }
            }));
        },

        /** 在多个主题间循环切换 */
        cycle: function () {
            var i = ALLOWED.indexOf(readPref());
            Theme.set(ALLOWED[(i + 1) % ALLOWED.length]);
        },

        ALLOWED: ALLOWED
    };

    // 跟随系统：系统偏好变化时，若当前为 auto 则实时更新
    if (mql) {
        var onChange = function () {
            if (readPref() === 'auto') apply('auto');
        };
        if (mql.addEventListener) mql.addEventListener('change', onChange);
        else if (mql.addListener) mql.addListener(onChange);
    }

    // 兜底：确保 data-theme 已写入（内联脚本失败时）
    if (!root.getAttribute('data-theme')) apply(readPref());

    window.DramatoolTheme = Theme;
})();
