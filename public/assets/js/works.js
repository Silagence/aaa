/**
 * 我的作品列表页交互
 * 依赖 window.DRAMATOOL_CTX = { baseUrl, csrfToken }
 * 功能：重命名、删除（软删）作品，均通过 JSON 接口完成
 */
(function () {
    'use strict';

    var ctx = window.DRAMATOOL_CTX || {};
    var baseUrl = ctx.baseUrl || '/';
    var csrfToken = ctx.csrfToken || '';

    function api(path) {
        return baseUrl.replace(/\/+$/, '') + '/' + path.replace(/^\/+/, '');
    }

    /**
     * 发送 POST 请求，返回解析后的 JSON
     */
    function post(path, data) {
        return fetch(api(path), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: new URLSearchParams(data).toString(),
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().catch(function () {
                return { ok: false, message: '服务器返回异常（HTTP ' + res.status + '）' };
            });
        });
    }

    function toast(message, type) {
        var box = document.getElementById('worksToast');
        if (!box) {
            box = document.createElement('div');
            box.id = 'worksToast';
            box.className = 'toast';
            document.body.appendChild(box);
        }
        box.textContent = message;
        box.className = 'toast' + (type ? ' toast--' + type : '');
        box.hidden = false;
        clearTimeout(box._timer);
        box._timer = setTimeout(function () { box.hidden = true; }, 2200);
    }

    function setBusy(btn, busy) {
        if (!btn) return;
        btn.disabled = busy;
    }

    /**
     * 重命名：用 prompt 取新名称，成功后原地更新卡片标题
     */
    function rename(btn) {
        var id = btn.dataset.id;
        var current = btn.dataset.title || '';
        var title = window.prompt('请输入新的作品名称', current);
        if (title === null) return;

        title = title.trim();
        if (title === '') { toast('作品名称不能为空', 'err'); return; }
        if (title === current) return;

        setBusy(btn, true);
        post('api/works/' + encodeURIComponent(id) + '/rename', {
            title: title,
            _token: csrfToken
        }).then(function (res) {
            setBusy(btn, false);
            if (!res.ok) { toast(res.message || '重命名失败', 'err'); return; }

            var card = btn.closest('.work-card');
            if (card) {
                var titleEl = card.querySelector('.work-card__title');
                if (titleEl) {
                    titleEl.textContent = res.title;
                    titleEl.title = res.title;
                }
                var coverLink = card.querySelector('.work-card__cover');
                if (coverLink) coverLink.setAttribute('title', res.title);
                var placeholder = card.querySelector('.work-card__placeholder');
                if (placeholder) placeholder.textContent = res.title.slice(0, 1);
            }
            btn.dataset.title = res.title;
            toast(res.message || '已重命名');
        }).catch(function () {
            setBusy(btn, false);
            toast('网络异常，请稍后重试', 'err');
        });
    }

    /**
     * 删除：二次确认后软删，成功后移除卡片
     */
    function remove(btn) {
        var id = btn.dataset.id;
        var title = btn.dataset.title || '该作品';
        if (!window.confirm('确定删除《' + title + '》吗？删除后不可恢复。')) return;

        setBusy(btn, true);
        post('api/works/' + encodeURIComponent(id) + '/delete', {
            _token: csrfToken
        }).then(function (res) {
            if (!res.ok) {
                setBusy(btn, false);
                toast(res.message || '删除失败', 'err');
                return;
            }

            var card = btn.closest('.work-card');
            if (card) {
                card.style.transition = 'opacity .2s ease, transform .2s ease';
                card.style.opacity = '0';
                card.style.transform = 'scale(.96)';
                setTimeout(function () {
                    card.remove();
                    if (!document.querySelector('.work-card')) {
                        window.location.reload();
                    }
                }, 200);
            }
            toast(res.message || '作品已删除');
        }).catch(function () {
            setBusy(btn, false);
            toast('网络异常，请稍后重试', 'err');
        });
    }

    /**
     * 发布 / 取消发布：成功后原地更新按钮文案与状态徽标
     */
    function togglePublish(btn) {
        var id = btn.dataset.id;
        var isPublic = btn.dataset.public === '1';
        var next = isPublic ? 0 : 1;

        setBusy(btn, true);
        post('api/works/' + encodeURIComponent(id) + '/publish', {
            public: next,
            _token: csrfToken
        }).then(function (res) {
            setBusy(btn, false);
            if (!res.ok) { toast(res.message || '操作失败', 'err'); return; }

            btn.dataset.public = String(res.is_public);
            btn.textContent = res.is_public === 1 ? '取消发布' : '发布';

            var card = btn.closest('.work-card');
            if (card) {
                var badge = card.querySelector('.badge');
                if (badge) {
                    badge.textContent = res.is_public === 1 ? '已发布' : '未发布';
                    badge.classList.toggle('badge--public', res.is_public === 1);
                }
            }
            toast(res.message || '操作成功');
        }).catch(function () {
            setBusy(btn, false);
            toast('网络异常，请稍后重试', 'err');
        });
    }

    document.addEventListener('click', function (e) {
        var themeBtn = e.target.closest('#themeToggle');
        if (themeBtn && window.DramatoolTheme) {
            window.DramatoolTheme.cycle();
            var names = { dark: '深色', light: '浅色', sepia: '护眼', auto: '跟随系统' };
            toast('主题：' + (names[window.DramatoolTheme.get()] || ''));
            return;
        }

        var publishBtn = e.target.closest('.js-publish');
        if (publishBtn) { togglePublish(publishBtn); return; }

        var renameBtn = e.target.closest('.js-rename');
        if (renameBtn) { rename(renameBtn); return; }

        var deleteBtn = e.target.closest('.js-delete');
        if (deleteBtn) { remove(deleteBtn); }
    });
})();
