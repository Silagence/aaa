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

    // ===== 公告管理 =====
    var annModal = document.getElementById('annModal');
    var annForm = document.getElementById('annForm');

    function openAnnModal(data) {
        if (!annModal || !annForm) return;
        annForm.reset();
        annForm.elements.id.value = data.id || '';
        annForm.elements.title.value = data.title || '';
        annForm.elements.content.value = data.content || '';
        annForm.elements.pinned.checked = data.pinned === '1';
        annForm.elements.status.checked = data.status !== '0';
        document.getElementById('annModalTitle').textContent = data.id ? '编辑公告' : '发布公告';
        annModal.hidden = false;
    }

    function closeAnnModal() {
        if (annModal) annModal.hidden = true;
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('.js-ann-new')) {
            openAnnModal({});
            return;
        }
        if (e.target.closest('.js-ann-cancel')) {
            closeAnnModal();
            return;
        }

        var editBtn = e.target.closest('.js-ann-edit');
        if (editBtn) {
            openAnnModal({
                id: editBtn.getAttribute('data-id'),
                title: editBtn.getAttribute('data-title'),
                content: editBtn.getAttribute('data-content'),
                status: editBtn.getAttribute('data-status'),
                pinned: editBtn.getAttribute('data-pinned')
            });
            return;
        }

        var toggleBtn = e.target.closest('.js-ann-toggle');
        if (toggleBtn) {
            var confirmText = toggleBtn.getAttribute('data-confirm');
            if (confirmText && !window.confirm(confirmText)) return;
            toggleBtn.disabled = true;
            post('/admin/announcements/' + toggleBtn.getAttribute('data-id') + '/toggle', {
                status: toggleBtn.getAttribute('data-status')
            }).then(function (res) {
                if (!res.ok) {
                    toggleBtn.disabled = false;
                    toast(res.message || '操作失败');
                    return;
                }
                toast(res.message || '操作成功', 'ok');
                setTimeout(function () { location.reload(); }, 600);
            }).catch(function () {
                toggleBtn.disabled = false;
                toast('网络异常，操作失败');
            });
            return;
        }

        var pinBtn = e.target.closest('.js-ann-pin');
        if (pinBtn) {
            pinBtn.disabled = true;
            post('/admin/announcements/' + pinBtn.getAttribute('data-id') + '/pin', {
                pinned: pinBtn.getAttribute('data-pinned')
            }).then(function (res) {
                if (!res.ok) {
                    pinBtn.disabled = false;
                    toast(res.message || '操作失败');
                    return;
                }
                toast(res.message || '操作成功', 'ok');
                setTimeout(function () { location.reload(); }, 600);
            }).catch(function () {
                pinBtn.disabled = false;
                toast('网络异常，操作失败');
            });
            return;
        }

        var delBtn = e.target.closest('.js-ann-delete');
        if (delBtn) {
            var delConfirm = delBtn.getAttribute('data-confirm');
            if (delConfirm && !window.confirm(delConfirm)) return;
            delBtn.disabled = true;
            post('/admin/announcements/' + delBtn.getAttribute('data-id') + '/delete', {})
                .then(function (res) {
                    if (!res.ok) {
                        delBtn.disabled = false;
                        toast(res.message || '操作失败');
                        return;
                    }
                    toast(res.message || '已删除', 'ok');
                    setTimeout(function () { location.reload(); }, 600);
                }).catch(function () {
                    delBtn.disabled = false;
                    toast('网络异常，操作失败');
                });
        }
    });

    if (annForm) {
        annForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var submitBtn = document.getElementById('annSubmit');
            var id = annForm.elements.id.value;
            var payload = {
                title: annForm.elements.title.value,
                content: annForm.elements.content.value,
                pinned: annForm.elements.pinned.checked ? '1' : '0',
                status: annForm.elements.status.checked ? '1' : '0'
            };
            var url = id ? '/admin/announcements/' + id + '/update' : '/admin/announcements';

            submitBtn.disabled = true;
            post(url, payload).then(function (res) {
                if (!res.ok) {
                    submitBtn.disabled = false;
                    toast(res.message || '保存失败');
                    return;
                }
                toast(res.message || '已保存', 'ok');
                setTimeout(function () { location.reload(); }, 600);
            }).catch(function () {
                submitBtn.disabled = false;
                toast('网络异常，保存失败');
            });
        });
    }
})();
