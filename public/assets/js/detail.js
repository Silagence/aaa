/**
 * 作品详情页交互：点赞、收藏、举报、复制链接、生成嵌入代码、评论区
 */
(function () {
    'use strict';

    var ctx = window.DRAMATOOL_DETAIL || {};
    var base = ((window.DRAMATOOL_CTX || {}).baseUrl || '/').replace(/\/+$/, '');
    var csrf = (window.DRAMATOOL_CTX || {}).csrfToken || '';

    function $(id) { return document.getElementById(id); }

    function toast(msg, type) {
        var t = $('toast');
        if (!t) { alert(msg); return; }
        t.textContent = msg;
        t.className = 'toast' + (type ? ' toast--' + type : '');
        t.hidden = false;
        clearTimeout(t._timer);
        t._timer = setTimeout(function () { t.hidden = true; }, 1800);
    }

    function copyText(text, okMsg) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                toast(okMsg, 'ok');
            }).catch(function () {
                fallbackCopy(text, okMsg);
            });
            return;
        }
        fallbackCopy(text, okMsg);
    }

    function fallbackCopy(text, okMsg) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); toast(okMsg, 'ok'); }
        catch (e) { toast('复制失败，请手动复制'); }
        document.body.removeChild(ta);
    }

    // ===== 点赞 =====
    function bindLike() {
        var btn = $('btnLike');
        if (!btn) return;

        btn.addEventListener('click', function () {
            if (!ctx.loggedIn) {
                toast('请先登录后再点赞');
                setTimeout(function () { location.href = base + '/login'; }, 800);
                return;
            }

            var liked = btn.getAttribute('data-liked') === '1';
            btn.disabled = true;

            var body = new URLSearchParams();
            body.append('action', liked ? 'unlike' : 'like');
            body.append('_token', csrf);

            fetch(base + '/api/works/' + encodeURIComponent(ctx.workId) + '/like', {
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
            }).then(function (res) {
                btn.disabled = false;
                if (!res.ok) {
                    toast(res.message || '操作失败');
                    return;
                }
                var nowLiked = !!res.liked;
                btn.setAttribute('data-liked', nowLiked ? '1' : '0');
                btn.classList.toggle('is-liked', nowLiked);
                $('likeLabel').textContent = nowLiked ? '♥ 已点赞' : '♡ 点赞';
                $('likeCount').textContent = res.like_count;
                toast(nowLiked ? '已点赞' : '已取消点赞', 'ok');
            }).catch(function () {
                btn.disabled = false;
                toast('网络异常，操作失败');
            });
        });
    }

    // ===== 收藏 =====
    function bindFavorite() {
        var btn = $('btnFavorite');
        if (!btn) return;

        btn.addEventListener('click', function () {
            if (!ctx.loggedIn) {
                toast('请先登录后再收藏');
                setTimeout(function () { location.href = base + '/login'; }, 800);
                return;
            }

            var favorited = btn.getAttribute('data-favorited') === '1';
            btn.disabled = true;

            var body = new URLSearchParams();
            body.append('action', favorited ? 'unfavorite' : 'favorite');
            body.append('_token', csrf);

            fetch(base + '/api/works/' + encodeURIComponent(ctx.workId) + '/favorite', {
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
            }).then(function (res) {
                btn.disabled = false;
                if (!res.ok) {
                    toast(res.message || '操作失败');
                    return;
                }
                var nowFavorited = !!res.favorited;
                btn.setAttribute('data-favorited', nowFavorited ? '1' : '0');
                btn.classList.toggle('is-favorited', nowFavorited);
                $('favoriteLabel').textContent = nowFavorited ? '★ 已收藏' : '☆ 收藏';
                $('favoriteCount').textContent = res.favorite_count;
                toast(nowFavorited ? '已收藏' : '已取消收藏', 'ok');
            }).catch(function () {
                btn.disabled = false;
                toast('网络异常，操作失败');
            });
        });
    }

    // ===== 举报 =====
    var reportTarget = { type: 'work', id: 0, label: '' };

    function openReport(type, id, label) {
        if (!ctx.loggedIn) {
            toast('请先登录后再举报');
            setTimeout(function () { location.href = base + '/login'; }, 800);
            return;
        }
        var modal = $('reportModal');
        if (!modal) return;

        reportTarget = { type: type, id: id, label: label || '' };
        $('reportTarget').textContent = label ? ('举报对象：' + label) : '';

        var form = $('reportForm');
        form.reset();
        modal.hidden = false;
    }

    function closeReport() {
        var modal = $('reportModal');
        if (modal) modal.hidden = true;
    }

    function bindReport() {
        var modal = $('reportModal');
        if (!modal) return;

        var btn = $('btnReport');
        if (btn) {
            btn.addEventListener('click', function () {
                openReport('work', ctx.workId, '作品');
            });
        }

        // 遮罩与取消按钮关闭
        modal.addEventListener('click', function (e) {
            if (e.target.closest('[data-close]')) closeReport();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) closeReport();
        });

        var form = $('reportForm');
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var checked = form.querySelector('input[name="reason"]:checked');
            if (!checked) { toast('请选择举报原因'); return; }

            var submit = $('btnReportSubmit');
            submit.disabled = true;

            var body = new URLSearchParams();
            body.append('target_type', reportTarget.type);
            body.append('target_id', reportTarget.id);
            body.append('reason', checked.value);
            body.append('detail', $('reportDetail').value.trim());
            body.append('_token', csrf);

            fetch(base + '/api/reports', {
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
            }).then(function (res) {
                submit.disabled = false;
                if (!res.ok) {
                    toast(res.message || '举报失败');
                    return;
                }
                closeReport();
                toast(res.message || '举报已提交', 'ok');
            }).catch(function () {
                submit.disabled = false;
                toast('网络异常，举报失败');
            });
        });
    }

    // ===== 分享 =====
    function bindShare() {
        var btn = $('btnShare');
        if (btn) {
            btn.addEventListener('click', function () {
                copyText(ctx.shareUrl, '链接已复制');
            });
        }

        var embedBtn = $('btnEmbed');
        var box = $('embedBox');
        if (embedBtn && box) {
            embedBtn.addEventListener('click', function () {
                box.hidden = !box.hidden;
                if (!box.hidden) {
                    var code = '<iframe src="' + ctx.embedUrl + '" width="960" height="540" '
                        + 'frameborder="0" allowfullscreen></iframe>';
                    $('embedCode').value = code;
                }
            });
        }

        var copyEmbed = $('btnCopyEmbed');
        if (copyEmbed) {
            copyEmbed.addEventListener('click', function () {
                copyText($('embedCode').value, '嵌入代码已复制');
            });
        }
    }

    // ===== 评论区 =====
    var commentPage = 0;
    var commentPages = 1;
    var commentLoading = false;

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function formatTime(s) {
        return String(s || '').slice(0, 16);
    }

    function commentNode(c) {
        var li = document.createElement('li');
        li.className = 'comment-item';
        li.setAttribute('data-id', c.id);

        var avatar = c.avatar
            ? '<img class="comment-item__avatar-img" src="' + escapeHtml(c.avatar) + '" alt="">'
            : escapeHtml((c.author || '?').slice(0, 1));

        li.innerHTML =
            '<span class="comment-item__avatar">' + avatar + '</span>' +
            '<div class="comment-item__body">' +
                '<div class="comment-item__head">' +
                    '<span class="comment-item__author">' + escapeHtml(c.author) + '</span>' +
                    '<span class="comment-item__time">' + escapeHtml(formatTime(c.created_at)) + '</span>' +
                '</div>' +
                '<p class="comment-item__text">' + escapeHtml(c.content).replace(/\n/g, '<br>') + '</p>' +
            '</div>' +
            (c.can_delete
                ? '<button class="link link--danger comment-item__del" type="button" data-id="' + c.id + '">删除</button>'
                : '');
        return li;
    }

    function renderComments(items, append) {
        var list = $('commentList');
        if (!list) return;
        if (!append) list.innerHTML = '';

        if (!items.length && !append) {
            list.innerHTML = '<li class="comment-empty">还没有评论，来抢沙发吧。</li>';
            return;
        }
        items.forEach(function (c) { list.appendChild(commentNode(c)); });
    }

    function loadComments(page) {
        if (commentLoading) return;
        commentLoading = true;

        fetch(base + '/api/works/' + encodeURIComponent(ctx.workId) + '/comments?page=' + page, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().catch(function () { return { ok: false }; });
        }).then(function (res) {
            commentLoading = false;
            if (!res.ok) {
                renderComments([], false);
                return;
            }
            commentPage = res.page;
            commentPages = res.pages;
            renderComments(res.items, page > 1);
            $('commentCount').textContent = res.total;
            $('btnMoreComments').hidden = commentPage >= commentPages;
        }).catch(function () {
            commentLoading = false;
            renderComments([], false);
        });
    }

    function bindComments() {
        var list = $('commentList');
        if (!list) return;

        loadComments(1);

        var more = $('btnMoreComments');
        if (more) {
            more.addEventListener('click', function () { loadComments(commentPage + 1); });
        }

        // 删除评论（事件委托）
        list.addEventListener('click', function (e) {
            var btn = e.target.closest('.comment-item__del');
            if (!btn) return;
            if (!window.confirm('确定删除这条评论吗？')) return;

            btn.disabled = true;
            var body = new URLSearchParams();
            body.append('_token', csrf);

            fetch(base + '/api/comments/' + encodeURIComponent(btn.getAttribute('data-id')) + '/delete', {
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
            }).then(function (res) {
                if (!res.ok) {
                    btn.disabled = false;
                    toast(res.message || '删除失败');
                    return;
                }
                var item = btn.closest('.comment-item');
                if (item) item.parentNode.removeChild(item);
                $('commentCount').textContent = res.total;
                if (!$('commentList').children.length) {
                    renderComments([], false);
                }
                toast('评论已删除', 'ok');
            }).catch(function () {
                btn.disabled = false;
                toast('网络异常，删除失败');
            });
        });

        // 发表评论
        var form = $('commentForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var input = $('commentInput');
                var content = input.value.trim();
                if (!content) { toast('评论内容不能为空'); return; }

                var btn = $('btnCommentSubmit');
                btn.disabled = true;

                var body = new URLSearchParams();
                body.append('content', content);
                body.append('_token', csrf);

                fetch(base + '/api/works/' + encodeURIComponent(ctx.workId) + '/comments', {
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
                }).then(function (res) {
                    btn.disabled = false;
                    if (!res.ok) {
                        toast(res.message || '发表失败');
                        return;
                    }
                    input.value = '';
                    $('commentHint').textContent = '';
                    $('commentCount').textContent = res.total;
                    // 新评论插到列表最前
                    var listEl = $('commentList');
                    var empty = listEl.querySelector('.comment-empty');
                    if (empty) listEl.innerHTML = '';
                    if (res.comment) listEl.insertBefore(commentNode(res.comment), listEl.firstChild);
                    toast('评论已发表', 'ok');
                }).catch(function () {
                    btn.disabled = false;
                    toast('网络异常，发表失败');
                });
            });

            // 字数提示
            var input = $('commentInput');
            input.addEventListener('input', function () {
                var max = parseInt(input.getAttribute('maxlength'), 10) || 500;
                var len = input.value.length;
                $('commentHint').textContent = len > max - 50 ? (len + ' / ' + max) : '';
            });
        }
    }

    bindLike();
    bindFavorite();
    bindReport();
    bindShare();
    bindComments();
})();
