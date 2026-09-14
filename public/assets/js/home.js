// 首页交互
// 一期："开始使用"按钮暂不响应（按需求暂不跳转至编辑器）
// 后续将启用：location.href = 'editor.php';
(function () {
    'use strict';

    var btnStart = document.getElementById('btnStart');
    if (btnStart) {
        btnStart.addEventListener('click', function () {
            // 跳转至编辑器
            location.href = 'editor.php';
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
