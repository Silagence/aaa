/**
 * 管理后台交互：举报处理、作品下架/恢复、用户启用/禁用
 *
 * 所有写操作均通过 fetch 提交，成功后刷新页面以同步列表与统计。
 */
(function () {
    'use strict';

    var ctx = window.DRAMATOOL_CTX || {};
    var base = (ctx.baseUrl || '/').replace(/\/$/, '');
    var csrf = ctx.csrfToken || '';

    function post(url, data) {
        var body = new URLSearchParams();
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        body.append('_token', csrf);

        return fetch(base + url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (res) {
            return res.json().catch(function () { return { ok: false, message: '响应解析失败' }; });
        });
    }

    function toast(message, type) {
        var el = document.createElement('div');
        el.className = 'toast' + (type === 'ok' ? ' toast--ok' : '');
        el.textContent = message;
        document.body.appendChild(el);
        setTimeout(function () { el.classList.add('is-show'); }, 10);
        setTimeout(function () {
            el.classList.remove('is-show');
            setTimeout(function () { el.remove(); }, 250);
        }, 2200);
    }

    // ===== 举报处理 =====
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-handle');
        if (!btn) return;

        var confirmText = btn.getAttribute('data-confirm');
        if (confirmText && !window.confirm(confirmText)) return;

        btn.disabled = true;
        post('/admin/reports/' + btn.getAttribute('data-id') + '/handle', {
            status: btn.getAttribute('data-status'),
            action: btn.getAttribute('data-action') || 'none'
        }).then(function (res) {
            if (!res.ok) {
                btn.disabled = false;
                toast(res.message || '操作失败');
                return;
            }
            toast(res.message || '已处理', 'ok');
            setTimeout(function () { location.reload(); }, 600);
        }).catch(function () {
            btn.disabled = false;
            toast('网络异常，操作失败');
        });
    });

    // ===== 作品下架 / 恢复 =====
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-work');
        if (!btn) return;

        var confirmText = btn.getAttribute('data-confirm');
        if (confirmText && !window.confirm(confirmText)) return;

        btn.disabled = true;
        post('/admin/works/' + btn.getAttribute('data-id') + '/unpublish', {
            action: btn.getAttribute('data-action') || 'unpublish'
        }).then(function (res) {
            if (!res.ok) {
                btn.disabled = false;
                toast(res.message || '操作失败');
                return;
            }
            toast(res.message || '操作成功', 'ok');
            setTimeout(function () { location.reload(); }, 600);
        }).catch(function () {
            btn.disabled = false;
            toast('网络异常，操作失败');
        });
    });

    // ===== 用户启用 / 禁用 =====
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-user');
        if (!btn) return;

        var confirmText = btn.getAttribute('data-confirm');
        if (confirmText && !window.confirm(confirmText)) return;

        btn.disabled = true;
        post('/admin/users/' + btn.getAttribute('data-id') + '/toggle', {
            status: btn.getAttribute('data-status')
        }).then(function (res) {
            if (!res.ok) {
                btn.disabled = false;
                toast(res.message || '操作失败');
                return;
            }
            toast(res.message || '操作成功', 'ok');
            setTimeout(function () { location.reload(); }, 600);
        }).catch(function () {
            btn.disabled = false;
            toast('网络异常，操作失败');
        });
    });
})();
