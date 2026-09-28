// 首页交互："开始使用"按钮跳转至编辑器
(function () {
    'use strict';

    var btnStart = document.getElementById('btnStart');
    if (btnStart) {
        btnStart.addEventListener('click', function () {
            // 跳转至编辑器
            location.href = 'editor';
        });
    }

    var btnTheme = document.getElementById('btnTheme');
    if (btnTheme) {
        btnTheme.addEventListener('click', function () {
            if (!window.DramatoolTheme) return;
            window.DramatoolTheme.cycle();
            var names = { dark: '深色', light: '浅色', sepia: '护眼', auto: '跟随系统' };
            btnTheme.textContent = '主题：' + (names[window.DramatoolTheme.get()] || '');
        });
    }
})();
