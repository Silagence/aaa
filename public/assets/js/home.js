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
})();
