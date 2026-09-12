/**
 * 编辑器主逻辑
 * 状态：work = { manifest, scenes[{id, nodes[]}] }
 * 渲染：素材库 / 场景列表 / 节点列表 / 节点属性卡片
 * 能力：场景与节点 CRUD、JSON 导入导出、localStorage 自动保存、预览跳转
 */
(function () {
    'use strict';

    // ============ 常量 ============
    var STORAGE_KEY = 'asha:editor:draft';
    var PREVIEW_KEY = 'asha:preview';
    var NODE_TYPES = ['bg', 'sprite', 'bgm', 'sfx', 'say', 'choose', 'var', 'goto'];

    // 节点默认值
    function defaultNode(type) {
        switch (type) {
            case 'bg':     return { type: 'bg',     ref: '', transition: 'fade' };
            case 'sprite': return { type: 'sprite', ref: '', position: 'center', animation: '' };
            case 'bgm':    return { type: 'bgm',    ref: '', loop: true };
            case 'sfx':    return { type: 'sfx',    ref: '' };
            case 'say':    return { type: 'say',    speaker: '', text: '', voice: '', speed: 30, speakers: '' };
            case 'choose': return { type: 'choose', options: [{ text: '选项1', next: '' }] };
            case 'var':    return { type: 'var',    set: { affection: '0' } };
            case 'goto':   return { type: 'goto',   next: '' };
            default:       return { type: type };
        }
    }

    var NODE_LABELS = {
        bg: '背景', sprite: '立绘', bgm: 'BGM', sfx: '音效',
        say: '对话', choose: '选项', var: '变量', goto: '跳转'
    };

    // ============ 状态 ============
    var state = {
        assets: { backgrounds: [], sprites: [], bgm: [], sfx: [] },
        work: null,
        selectedSceneId: null,
        selectedNodeIndex: -1,
        activeAssetTab: 'bg',
        spriteKeyword: '',
        spriteCategory: '全部',
        spriteCharacter: null
    };

    function newWork() {
        return {
            manifest: {
                name: '未命名作品',
                author: '',
                version: '1.0.0',
                engine: 'asha@1.0',
                startScene: 'scene_001',
                canvas: { width: 1280, height: 720, orientation: 'landscape' }
            },
            scenes: []
        };
    }

    // 新建作品：若当前有未保存改动则二次确认，然后重置为初始状态
    function newWorkConfirm() {
        if ($('saveState').classList.contains('is-dirty') &&
            !window.confirm('当前作品有未保存的修改，确定要新建并清空吗？')) {
            return;
        }
        state.work = newWork();
        state.selectedSceneId = null;
        state.selectedNodeIndex = -1;
        state.activeAssetTab = 'bg';
        ensureFirstScene();
        // tabs 高亮复位到"背景"
        Array.prototype.forEach.call($('assetTabs').children, function (c, i) {
            c.classList.toggle('is-active', i === 0);
        });
        renderAll();
        saveDraft();
        toast('已新建作品');
    }

    // ============ 工具 ============
    function $(id) { return document.getElementById(id); }
    function el(tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (html != null) e.innerHTML = html;
        return e;
    }
    function pad2(n) { return n < 10 ? '0' + n : '' + n; }
    function genSceneId() {
        var max = 0;
        state.work.scenes.forEach(function (s) {
            var m = /^scene_(\d+)$/.exec(s.id);
            if (m) { var n = parseInt(m[1], 10); if (n > max) max = n; }
        });
        return 'scene_' + pad2(max + 1);
    }
    function findScene(id) {
        for (var i = 0; i < state.work.scenes.length; i++) {
            if (state.work.scenes[i].id === id) return state.work.scenes[i];
        }
        return null;
    }
    function currentScene() { return findScene(state.selectedSceneId); }
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function toast(msg, type) {
        var t = $('toast');
        t.textContent = msg;
        t.className = 'toast' + (type ? ' toast--' + type : '');
        t.hidden = false;
        clearTimeout(t._timer);
        t._timer = setTimeout(function () { t.hidden = true; }, 2000);
    }

    // ============ 持久化 ============
    var saveTimer = null;
    function markDirty() {
        $('saveState').className = 'topbar__save is-dirty';
        $('saveState').textContent = '保存中…';
        clearTimeout(saveTimer);
        saveTimer = setTimeout(saveDraft, 600);
    }
    function saveDraft() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state.work));
            $('saveState').className = 'topbar__save is-saved';
            $('saveState').textContent = '已保存';
        } catch (e) {
            $('saveState').textContent = '保存失败';
            console.warn('save failed', e);
        }
    }
    function loadDraft() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return null;
            var w = JSON.parse(raw);
            if (!w.manifest || !Array.isArray(w.scenes)) return null;
            return w;
        } catch (e) { return null; }
    }

    // ============ 素材库加载 ============
    function loadAssets(cb) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'assets/manifest.json', true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 200) {
                try {
                    var m = JSON.parse(xhr.responseText);
                    state.assets = {
                        backgrounds: m.backgrounds || [],
                        sprites: m.sprites || [],
                        bgm: m.bgm || [],
                        sfx: m.sfx || []
                    };
                } catch (e) { console.warn('manifest 解析失败', e); }
            }
            cb();
        };
        xhr.send();
    }

    function assetListByTab(tab) {
        switch (tab) {
            case 'bg':     return state.assets.backgrounds;
            case 'sprite': return state.assets.sprites;
            case 'bgm':    return state.assets.bgm;
            case 'sfx':    return state.assets.sfx;
        }
        return [];
    }
    function findAsset(tab, id) {
        var list = assetListByTab(tab);
        for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i];
        return null;
    }

    // ============ 渲染：素材库 ============
    // 立绘采用"角色列表 → 立绘详情"两级导航：
    // 角色数量可能达数百，平铺渲染会创建上千 DOM 节点导致卡顿，
    // 因此第一级只渲染角色卡片（数量级为角色数），进入角色后才渲染其立绘。
    function spriteCategories() {
        var seen = {}, out = [];
        state.assets.sprites.forEach(function (a) {
            var c = a.category || '其他';
            if (!seen[c]) { seen[c] = true; out.push(c); }
        });
        return out;
    }

    // 按 类型 → 角色 聚合，返回角色数组
    function buildCharacterList() {
        var map = {}, out = [];
        state.assets.sprites.forEach(function (a) {
            var cat = a.category || '其他';
            var ch = a.character || '未设置角色';
            var key = cat + '\u0000' + ch;
            if (!map[key]) {
                map[key] = { category: cat, character: ch, items: [] };
                out.push(map[key]);
            }
            map[key].items.push(a);
        });
        return out;
    }

    function renderSpriteFilter() {
        var box = $('spriteCats');
        box.innerHTML = '';
        var cats = ['全部'].concat(spriteCategories());
        cats.forEach(function (c) {
            var b = el('button', 'sprite-cat' + (c === state.spriteCategory ? ' is-active' : ''), escapeHtml(c));
            b.type = 'button';
            b.addEventListener('click', function () {
                state.spriteCategory = c;
                state.spriteCharacter = null;   // 切换类型时退回角色列表
                renderAssetGrid();
            });
            box.appendChild(b);
        });
    }

    function renderAssetGrid() {
        var grid = $('assetGrid');
        hidePreview();
        grid.innerHTML = '';
        var isSprite = (state.activeAssetTab === 'sprite');
        $('spriteFilter').hidden = !isSprite;

        if (isSprite) {
            renderSpriteFilter();
            renderSpritePanel(grid);
            return;
        }

        var list = assetListByTab(state.activeAssetTab);
        if (!list.length) {
            grid.appendChild(el('p', 'placeholder', '此分类暂无素材'));
            return;
        }
        list.forEach(function (item) {
            grid.appendChild(buildAssetCard(item, state.activeAssetTab === 'bgm' || state.activeAssetTab === 'sfx'));
        });
    }

    // 立绘面板：根据是否已选中角色，渲染角色列表或立绘详情
    function renderSpritePanel(grid) {
        var chars = buildCharacterList();
        var kw = (state.spriteKeyword || '').trim().toLowerCase();

        // 类型筛选
        if (state.spriteCategory !== '全部') {
            chars = chars.filter(function (c) { return c.category === state.spriteCategory; });
        }
        // 角色名搜索
        if (kw) {
            chars = chars.filter(function (c) {
                return c.character.toLowerCase().indexOf(kw) >= 0 ||
                       c.category.toLowerCase().indexOf(kw) >= 0;
            });
        }

        // 已选中角色 → 立绘详情
        if (state.spriteCharacter) {
            var cur = null;
            chars.forEach(function (c) {
                if (c.character === state.spriteCharacter.character &&
                    c.category === state.spriteCharacter.category) cur = c;
            });
            if (cur) { renderSpriteDetail(grid, cur); return; }
            state.spriteCharacter = null;   // 角色被筛掉则退回列表
        }

        // 角色列表
        if (!chars.length) {
            grid.appendChild(el('p', 'placeholder', '没有匹配的角色'));
            return;
        }
        var bar = el('div', 'sprite-result-bar');
        bar.appendChild(el('span', 'sprite-result-bar__text', '共 ' + chars.length + ' 个角色'));
        grid.appendChild(bar);

        chars.forEach(function (c) {
            var card = el('div', 'char-item');
            var thumb = el('div', 'char-item__thumb');
            var img = document.createElement('img');
            img.src = 'assets/' + c.items[0].thumb;
            img.alt = c.character;
            img.loading = 'lazy';
            img.draggable = false;
            thumb.appendChild(img);
            card.appendChild(thumb);
            // 悬停预览该角色的首张立绘
            thumb.addEventListener('mouseenter', function () {
                showPreview('assets/' + c.items[0].src, c.character, card);
            });
            thumb.addEventListener('mouseleave', hidePreview);

            var info = el('div', 'char-item__info');
            info.appendChild(el('div', 'char-item__name', escapeHtml(c.character)));
            info.appendChild(el('div', 'char-item__meta',
                escapeHtml(c.category) + ' · ' + c.items.length + ' 张'));
            card.appendChild(info);

            card.addEventListener('click', function () {
                state.spriteCharacter = { character: c.character, category: c.category };
                renderAssetGrid();
            });
            grid.appendChild(card);
        });
    }

    // 单个角色的立绘详情
    function renderSpriteDetail(grid, c) {
        var back = el('button', 'sprite-back', '← 返回角色列表');
        back.type = 'button';
        back.addEventListener('click', function () {
            state.spriteCharacter = null;
            renderAssetGrid();
        });
        grid.appendChild(back);

        var bar = el('div', 'sprite-result-bar');
        bar.appendChild(el('span', 'sprite-group__cat', escapeHtml(c.category)));
        bar.appendChild(el('span', 'sprite-group__char', escapeHtml(c.character)));
        bar.appendChild(el('span', 'sprite-result-bar__text', c.items.length + ' 张立绘'));
        grid.appendChild(bar);

        var g = el('div', 'asset-grid');
        c.items.forEach(function (item) { g.appendChild(buildAssetCard(item, false)); });
        grid.appendChild(g);
    }

    // ============ 素材悬停预览 ============
    // 鼠标悬停在缩略图上时，在光标旁浮出完整图片。
    // 用单个复用的浮层元素，避免为每张卡片创建 DOM。
    var previewEl = null;

    function ensurePreviewEl() {
        if (previewEl) return previewEl;
        previewEl = el('div', 'asset-preview');
        previewEl.hidden = true;
        var img = document.createElement('img');
        img.draggable = false;
        img.alt = '';
        previewEl.appendChild(img);
        previewEl.appendChild(el('div', 'asset-preview__name'));
        document.body.appendChild(previewEl);
        return previewEl;
    }

    function showPreview(src, name, anchor) {
        var box = ensurePreviewEl();
        var img = box.querySelector('img');
        if (img.getAttribute('src') !== src) img.src = src;
        box.querySelector('.asset-preview__name').textContent = name || '';
        box.hidden = false;
        positionPreview(anchor);
        // 图片未加载完时 offsetHeight 偏小，会导致定位溢出视口；
        // 加载完成后重新定位一次（已缓存的图片 complete 为 true，不会重复触发）。
        if (!img.complete) {
            img.onload = function () { positionPreview(anchor); };
        }
    }

    function positionPreview(anchor) {
        if (!previewEl || previewEl.hidden) return;
        var r = anchor.getBoundingClientRect();
        var pw = previewEl.offsetWidth;
        var ph = previewEl.offsetHeight;
        // 优先显示在卡片右侧，空间不足则显示在左侧
        var left = r.right + 12;
        if (left + pw > window.innerWidth - 8) left = r.left - pw - 12;
        if (left < 8) left = 8;
        var top = r.top;
        if (top + ph > window.innerHeight - 8) top = window.innerHeight - ph - 8;
        if (top < 8) top = 8;
        previewEl.style.left = left + 'px';
        previewEl.style.top = top + 'px';
    }

    function hidePreview() {
        if (previewEl) previewEl.hidden = true;
    }

    // 构建单个素材卡片
    function buildAssetCard(item, isAudio) {
        var card = el('div', 'asset-item' + (isAudio ? ' asset-item--audio' : ''));
        var thumb = el('div', 'asset-item__thumb');
        if (isAudio) {
            thumb.textContent = '♪';
        } else {
            var img = document.createElement('img');
            img.src = 'assets/' + item.thumb;
            img.alt = item.name;
            img.loading = 'lazy';
            img.draggable = false;
            thumb.appendChild(img);
            // 悬停预览完整图片
            thumb.addEventListener('mouseenter', function () {
                showPreview('assets/' + item.src, item.name, card);
            });
            thumb.addEventListener('mouseleave', hidePreview);
        }
        card.appendChild(thumb);
        card.appendChild(el('div', 'asset-item__name', escapeHtml(item.name)));
        // 立绘展示 character 值，方便用户在"说话角色"里填写
        if (state.activeAssetTab === 'sprite') {
            card.appendChild(el('div', 'asset-item__char',
                item.character ? '角色：' + escapeHtml(item.character) : '角色：未设置'));
        }
        card.addEventListener('click', function () { onAssetClick(item); });
        return card;
    }

    // 点击素材 → 在当前场景插入对应节点
    function onAssetClick(item) {
        var sc = currentScene();
        if (!sc) { toast('请先创建/选中场景', 'err'); return; }
        var type = state.activeAssetTab; // bg/sprite/bgm/sfx 与节点 type 同名
        var node = defaultNode(type);
        node.ref = item.id;
        sc.nodes.push(node);
        state.selectedNodeIndex = sc.nodes.length - 1;
        try {
            renderAll();
            markDirty();
        } catch (e) {
            console.error('renderAll failed after asset click', e);
        }
        toast('已插入 ' + (NODE_LABELS[type] || type) + ' 节点');
    }

    // ============ 渲染：场景列表 ============
    function renderSceneList() {
        var ul = $('sceneList');
        ul.innerHTML = '';
        state.work.scenes.forEach(function (s) {
            var li = el('li', 'scene-item' + (s.id === state.selectedSceneId ? ' is-active' : ''));
            li.appendChild(el('span', 'scene-item__id', escapeHtml(s.id)));
            li.appendChild(el('span', 'scene-item__count', String(s.nodes.length)));
            li.addEventListener('click', function () {
                state.selectedSceneId = s.id;
                state.selectedNodeIndex = -1;
                renderAll();
            });
            ul.appendChild(li);
        });
        if (!state.work.scenes.length) {
            ul.appendChild(el('li', 'placeholder', '暂无场景，点击右上角新建'));
        }
    }

    // ============ 渲染：节点列表 ============
    function nodeTitle(node) {
        switch (node.type) {
            case 'bg': case 'sprite': case 'bgm': case 'sfx':
                return node.ref ? escapeHtml(node.ref) : '<未指定素材>';
            case 'say':
                return (node.speaker ? escapeHtml(node.speaker) + '：' : '') +
                       (node.text ? escapeHtml(node.text) : '<空对话>');
            case 'choose':
                return node.options.length + ' 个选项';
            case 'var':
                var keys = Object.keys(node.set || {});
                return keys.length ? escapeHtml(keys.join(', ')) : '<空变量>';
            case 'goto':
                return node.next ? '→ ' + escapeHtml(node.next) : '<未指定>';
        }
        return '';
    }
    function nodeSub(node) {
        switch (node.type) {
            case 'sprite': return 'pos: ' + (node.position || 'center') + (node.animation ? ' · ' + node.animation : '');
            case 'bg':     return node.transition ? 'transition: ' + node.transition : '';
            case 'bgm':    return node.loop ? 'loop' : 'no-loop';
            case 'say':    return node.voice ? 'voice: ' + node.voice : '';
            case 'choose': return node.options.map(function (o) { return o.text || '?'; }).join(' / ');
            case 'var':
                return Object.keys(node.set || {}).map(function (k) {
                    return k + '=' + node.set[k];
                }).join(', ');
            case 'goto':   return '';
        }
        return '';
    }

    // 背景/立绘节点的缩略图；其他类型或未指定素材时返回 null
    function buildNodeThumb(node) {
        if (node.type !== 'bg' && node.type !== 'sprite') return null;
        var a = findAsset(node.type, node.ref);
        if (!a) return null;
        var box = el('div', 'node__thumb');
        var img = document.createElement('img');
        img.src = 'assets/' + a.thumb;
        img.alt = a.name || '';
        img.loading = 'lazy';
        img.draggable = false;
        box.appendChild(img);
        // 悬停预览完整图片
        box.addEventListener('mouseenter', function () {
            showPreview('assets/' + a.src, a.name, box);
        });
        box.addEventListener('mouseleave', hidePreview);
        return box;
    }

    function renderNodeList() {
        var box = $('nodeList');
        box.innerHTML = '';
        var sc = currentScene();
        if (!sc) {
            $('emptyHint').style.display = 'flex';
            $('emptyHint').querySelector('p').textContent = '请先在左侧创建或选中场景。';
            return;
        }
        $('sceneId').value = sc.id;
        if (!sc.nodes.length) {
            $('emptyHint').style.display = 'flex';
            $('emptyHint').querySelector('p').textContent = '当前场景暂无节点。';
            return;
        }
        $('emptyHint').style.display = 'none';
        sc.nodes.forEach(function (node, i) {
            var card = el('div', 'node node--' + node.type +
                (i === state.selectedNodeIndex ? ' is-selected' : ''));
            card.appendChild(el('div', 'node__index', String(i + 1)));
            // 背景/立绘节点展示素材缩略图
            var thumb = buildNodeThumb(node);
            if (thumb) card.appendChild(thumb);
            var body = el('div', 'node__body');
            body.appendChild(el('div', 'node__type', NODE_LABELS[node.type] || node.type));
            body.appendChild(el('div', 'node__title', nodeTitle(node)));
            var sub = nodeSub(node);
            if (sub) body.appendChild(el('div', 'node__sub', sub));
            card.appendChild(body);
            var acts = el('div', 'node__actions');
            var up = el('button', 'node__btn', '↑'); up.title = '上移';
            var dn = el('button', 'node__btn', '↓'); dn.title = '下移';
            var del = el('button', 'node__btn node__btn--del', '✕'); del.title = '删除';
            up.addEventListener('click', function (e) { e.stopPropagation(); moveNode(i, -1); });
            dn.addEventListener('click', function (e) { e.stopPropagation(); moveNode(i, 1); });
            del.addEventListener('click', function (e) { e.stopPropagation(); delNode(i); });
            acts.appendChild(up); acts.appendChild(dn); acts.appendChild(del);
            card.appendChild(acts);
            card.addEventListener('click', function () {
                state.selectedNodeIndex = i; renderAll();
            });
            box.appendChild(card);
        });
    }

    // 仅局部更新中栏当前选中节点卡片的标题/副标题。
    // 用于属性面板输入时同步预览，避免调用 renderAll/renderNodeList
    // 重建 DOM 而导致正在编辑的输入框失焦或中栏滚动位置跳动。
    function refreshActiveNodeCard() {
        var card = document.querySelector('#nodeList .node.is-selected');
        if (!card) return;
        var sc = currentScene();
        if (!sc || state.selectedNodeIndex < 0) return;
        var node = sc.nodes[state.selectedNodeIndex];
        if (!node) return;
        var titleEl = card.querySelector('.node__title');
        if (titleEl) titleEl.innerHTML = nodeTitle(node);
        var sub = nodeSub(node);
        var subEl = card.querySelector('.node__sub');
        if (sub) {
            if (subEl) {
                subEl.innerHTML = sub;
            } else {
                var body = card.querySelector('.node__body');
                if (body) body.appendChild(el('div', 'node__sub', sub));
            }
        } else if (subEl) {
            subEl.parentNode.removeChild(subEl);
        }
    }

    // ============ 渲染：节点属性卡片 ============
    function renderProps() {
        var panel = $('propsPanel');
        panel.innerHTML = '';
        var sc = currentScene();
        if (!sc) { panel.appendChild(el('p', 'placeholder', '未选中场景')); return; }
        if (state.selectedNodeIndex < 0 ||
            state.selectedNodeIndex >= sc.nodes.length) {
            panel.appendChild(el('p', 'placeholder', '选中一个节点以编辑其属性。'));
            return;
        }
        var node = sc.nodes[state.selectedNodeIndex];
        panel.appendChild(buildPropsForm(node));
    }

    function buildPropsForm(node) {
        var wrap = el('div', 'props-form');
        wrap.appendChild(fieldRow('类型', typeText(node.type)));
        switch (node.type) {
            case 'bg':     bgFields(wrap, node); break;
            case 'sprite': spriteFields(wrap, node); break;
            case 'bgm':    bgmFields(wrap, node); break;
            case 'sfx':    sfxFields(wrap, node); break;
            case 'say':    sayFields(wrap, node); break;
            case 'choose': chooseFields(wrap, node); break;
            case 'var':    varFields(wrap, node); break;
            case 'goto':   gotoFields(wrap, node); break;
        }
        return wrap;
    }
    function typeText(t) { return NODE_LABELS[t] || t; }

    function fieldRow(label, contentHtml) {
        var f = el('div', 'field');
        f.appendChild(el('span', 'field__label', label));
        f.appendChild(el('div', 'field__control', contentHtml));
        return f;
    }
    // 素材引用下拉
    function assetSelect(tab, value) {
        var list = assetListByTab(tab);
        var s = el('select', 'field__input');
        s.appendChild(el('option', '', '— 未指定 —'));
        list.forEach(function (a) {
            var o = el('option', '', escapeHtml(a.name));
            o.value = a.id;
            if (a.id === value) o.selected = true;
            s.appendChild(o);
        });
        return s;
    }
    function sceneSelect(value, includeNone) {
        var s = el('select', 'field__input');
        if (includeNone) s.appendChild(el('option', '', '— 不跳转（结束） —'));
        state.work.scenes.forEach(function (sc) {
            var o = el('option', '', escapeHtml(sc.id));
            o.value = sc.id;
            if (sc.id === value) o.selected = true;
            s.appendChild(o);
        });
        return s;
    }

    function bgFields(w, n) {
        var sel = assetSelect('bg', n.ref);
        sel.addEventListener('change', function () { n.ref = sel.value; renderAll(); markDirty(); });
        w.appendChild(fieldRow('背景素材', '')).appendChild(sel);
        var tr = textInput(n.transition || '', '过渡效果 fade/none');
        tr.addEventListener('input', function () { n.transition = tr.value; markDirty(); });
        w.appendChild(fieldRow('过渡', '')).appendChild(tr);
    }
    function spriteFields(w, n) {
        var sel = assetSelect('sprite', n.ref);
        sel.addEventListener('change', function () { n.ref = sel.value; renderAll(); markDirty(); });
        w.appendChild(fieldRow('立绘素材', '')).appendChild(sel);
        var pos = selectInput(['left', 'center', 'right'], n.position || 'center');
        pos.addEventListener('change', function () { n.position = pos.value; renderAll(); markDirty(); });
        w.appendChild(fieldRow('位置', '')).appendChild(pos);
        var ani = textInput(n.animation || '', '动画 fadeIn/none');
        // 输入时仅刷新中栏节点预览，不调用 renderAll，避免属性面板重建导致失焦
        ani.addEventListener('input', function () { n.animation = ani.value; refreshActiveNodeCard(); markDirty(); });
        w.appendChild(fieldRow('动画', '')).appendChild(ani);
    }
    function bgmFields(w, n) {
        var sel = assetSelect('bgm', n.ref);
        sel.addEventListener('change', function () { n.ref = sel.value; markDirty(); });
        w.appendChild(fieldRow('BGM', '')).appendChild(sel);
        var lp = el('label', 'field field--inline');
        var cb = document.createElement('input');
        cb.type = 'checkbox'; cb.checked = !!n.loop;
        cb.addEventListener('change', function () { n.loop = cb.checked; markDirty(); });
        lp.appendChild(cb);
        lp.appendChild(el('span', 'field__label', '循环播放'));
        w.appendChild(lp);
    }
    function sfxFields(w, n) {
        var sel = assetSelect('sfx', n.ref);
        sel.addEventListener('change', function () { n.ref = sel.value; markDirty(); });
        w.appendChild(fieldRow('音效', '')).appendChild(sel);
    }
    function sayFields(w, n) {
        var sp = textInput(n.speaker || '', '角色名');
        // 输入时仅局部更新中栏预览，不重建属性面板，避免每输入一个字就失焦
        sp.addEventListener('input', function () { n.speaker = sp.value; refreshActiveNodeCard(); markDirty(); });
        w.appendChild(fieldRow('说话人', '')).appendChild(sp);
        var spk = textInput(n.speakers || '', '立绘 character 值，多个用空格分隔；留空表示不变暗任何人');
        spk.addEventListener('input', function () { n.speakers = spk.value; markDirty(); });
        w.appendChild(fieldRow('说话角色', '')).appendChild(spk);
        var tx = el('textarea', 'field__input');
        tx.rows = 3; tx.value = n.text || '';
        tx.placeholder = '对话文本';
        tx.addEventListener('input', function () { n.text = tx.value; refreshActiveNodeCard(); markDirty(); });
        var fr = fieldRow('文本', ''); fr.appendChild(tx); w.appendChild(fr);
        var vc = textInput(n.voice || '', '语音文件（可选）');
        vc.addEventListener('input', function () { n.voice = vc.value; markDirty(); });
        w.appendChild(fieldRow('语音', '')).appendChild(vc);
        var sp2 = numberInput(n.speed != null ? n.speed : 30, 1, 200, '打字速度（字符/秒）');
        sp2.addEventListener('input', function () { n.speed = parseInt(sp2.value, 10) || 30; markDirty(); });
        w.appendChild(fieldRow('打字速度', '')).appendChild(sp2);
    }
    function chooseFields(w, n) {
        var list = el('div', 'option-list');
        function renderOptions() {
            list.innerHTML = '';
            (n.options || []).forEach(function (opt, idx) {
                var row = el('div', 'option-row');
                var t = textInput(opt.text || '', '选项文字');
                t.addEventListener('input', function () { opt.text = t.value; refreshActiveNodeCard(); markDirty(); });
                var nx = sceneSelect(opt.next || '', true);
                nx.addEventListener('change', function () { opt.next = nx.value; markDirty(); });
                var del = el('button', 'option-row__btn', '✕'); del.title = '删除选项';
                del.addEventListener('click', function () {
                    n.options.splice(idx, 1);
                    renderOptions(); renderAll(); markDirty();
                });
                row.appendChild(t); row.appendChild(nx); row.appendChild(del);
                list.appendChild(row);
            });
        }
        renderOptions();
        var fr = fieldRow('选项（' + (n.options || []).length + '）', ''); fr.appendChild(list); w.appendChild(fr);
        var add = el('button', 'btn btn--ghost btn--xs', '+ 添加选项');
        add.addEventListener('click', function () {
            n.options = n.options || [];
            n.options.push({ text: '新选项', next: '' });
            renderOptions(); renderAll(); markDirty();
        });
        w.appendChild(add);
    }
    function varFields(w, n) {
        var list = el('div', 'kv-list');
        function renderKv() {
            list.innerHTML = '';
            Object.keys(n.set || {}).forEach(function (k) {
                var row = el('div', 'kv-row');
                var kIn = textInput(k, '变量名');
                var vIn = textInput(String(n.set[k]), '值（支持 +1 / -1 / 数字 / 字符串）');
                var del = el('button', 'option-row__btn', '✕');
                del.addEventListener('click', function () { delete n.set[k]; renderKv(); renderAll(); markDirty(); });
                kIn.addEventListener('input', function () {
                    var nk = kIn.value;
                    if (nk !== k) { var v = n.set[k]; delete n.set[k]; n.set[nk] = v; k = nk; markDirty(); }
                });
                vIn.addEventListener('input', function () { n.set[k] = vIn.value; markDirty(); });
                row.appendChild(kIn); row.appendChild(vIn); row.appendChild(del);
                list.appendChild(row);
            });
        }
        renderKv();
        var fr = fieldRow('变量赋值', ''); fr.appendChild(list); w.appendChild(fr);
        var add = el('button', 'btn btn--ghost btn--xs', '+ 添加变量');
        add.addEventListener('click', function () {
            n.set = n.set || {};
            var i = 1; while (n.set['var_' + i]) i++;
            n.set['var_' + i] = '0';
            renderKv(); renderAll(); markDirty();
        });
        w.appendChild(add);
    }
    function gotoFields(w, n) {
        var sel = sceneSelect(n.next || '', true);
        sel.addEventListener('change', function () { n.next = sel.value; renderAll(); markDirty(); });
        w.appendChild(fieldRow('跳转目标场景', '')).appendChild(sel);
    }

    // 输入控件工厂
    function textInput(value, placeholder) {
        var i = document.createElement('input');
        i.type = 'text'; i.className = 'field__input';
        i.value = value || ''; if (placeholder) i.placeholder = placeholder;
        return i;
    }
    function numberInput(value, min, max, placeholder) {
        var i = document.createElement('input');
        i.type = 'number'; i.className = 'field__input';
        i.value = value; if (min != null) i.min = min; if (max != null) i.max = max;
        if (placeholder) i.placeholder = placeholder;
        return i;
    }
    function selectInput(options, value) {
        var s = el('select', 'field__input');
        options.forEach(function (o) {
            var opt = el('option', '', String(o));
            opt.value = o; if (o === value) opt.selected = true;
            s.appendChild(opt);
        });
        return s;
    }

    // ============ 渲染：作品信息 ============
    function renderWorkInfo() {
        var m = state.work.manifest;
        if (!m.canvas) m.canvas = { width: 1280, height: 720, orientation: 'landscape' };
        if (document.activeElement !== $('workName')) $('workName').value = m.name || '';
        if (document.activeElement !== $('workAuthor')) $('workAuthor').value = m.author || '';
        if (document.activeElement !== $('canvasW')) $('canvasW').value = m.canvas.width;
        if (document.activeElement !== $('canvasH')) $('canvasH').value = m.canvas.height;
        var sel = $('workStartScene');
        sel.innerHTML = '';
        state.work.scenes.forEach(function (sc) {
            var o = el('option', '', escapeHtml(sc.id));
            o.value = sc.id;
            if (sc.id === m.startScene) o.selected = true;
            sel.appendChild(o);
        });
    }

    // ============ 全量渲染 ============
    function renderAll() {
        renderAssetGrid();
        renderSceneList();
        renderNodeList();
        renderProps();
        renderWorkInfo();
    }

    // ============ 操作：场景 CRUD ============
    function addScene() {
        var id = genSceneId();
        var sc = { id: id, nodes: [] };
        state.work.scenes.push(sc);
        state.selectedSceneId = id;
        state.selectedNodeIndex = -1;
        renderAll(); markDirty();
        toast('已新建场景 ' + id);
    }
    function delScene() {
        var sc = currentScene();
        if (!sc) { toast('未选中场景', 'err'); return; }
        if (!confirm('确定删除场景 ' + sc.id + ' 吗？')) return;
        var idx = state.work.scenes.indexOf(sc);
        state.work.scenes.splice(idx, 1);
        if (state.work.scenes.length) {
            state.selectedSceneId = state.work.scenes[Math.max(0, idx - 1)].id;
        } else {
            state.selectedSceneId = null;
        }
        state.selectedNodeIndex = -1;
        renderAll(); markDirty();
    }
    function renameScene(newId) {
        var sc = currentScene();
        if (!sc) return;
        newId = newId.trim();
        if (!newId) { toast('场景ID不能为空', 'err'); renderAll(); return; }
        if (newId !== sc.id && findScene(newId)) { toast('ID 已存在', 'err'); renderAll(); return; }
        if (state.work.manifest.startScene === sc.id) {
            state.work.manifest.startScene = newId;
        }
        // 同步 goto/choose 中的引用
        state.work.scenes.forEach(function (s) {
            s.nodes.forEach(function (n) {
                if (n.type === 'goto' && n.next === sc.id) n.next = newId;
                if (n.type === 'choose') (n.options || []).forEach(function (o) {
                    if (o.next === sc.id) o.next = newId;
                });
            });
        });
        sc.id = newId;
        state.selectedSceneId = newId;
        renderAll(); markDirty();
    }

    // ============ 操作：节点 CRUD ============
    function addNode(type) {
        var sc = currentScene();
        if (!sc) { toast('请先创建/选中场景', 'err'); return; }
        sc.nodes.push(defaultNode(type));
        state.selectedNodeIndex = sc.nodes.length - 1;
        renderAll(); markDirty();
        toast('已插入 ' + (NODE_LABELS[type] || type) + ' 节点');
    }
    function delNode(i) {
        var sc = currentScene();
        if (!sc || i < 0 || i >= sc.nodes.length) return;
        sc.nodes.splice(i, 1);
        if (state.selectedNodeIndex >= sc.nodes.length) state.selectedNodeIndex = sc.nodes.length - 1;
        renderAll(); markDirty();
    }
    function moveNode(i, dir) {
        var sc = currentScene();
        if (!sc) return;
        var j = i + dir;
        if (j < 0 || j >= sc.nodes.length) return;
        var tmp = sc.nodes[i]; sc.nodes[i] = sc.nodes[j]; sc.nodes[j] = tmp;
        if (state.selectedNodeIndex === i) state.selectedNodeIndex = j;
        else if (state.selectedNodeIndex === j) state.selectedNodeIndex = i;
        renderAll(); markDirty();
    }

    // ============ 导入导出 ============
    function buildExport() {
        var w = state.work;
        // 组装 assets 段（来自 manifest 的素材清单 + 实际被引用的素材）
        var assets = [];
        ['backgrounds', 'sprites', 'bgm', 'sfx'].forEach(function (k) {
            var typeMap = { backgrounds: 'bg', sprites: 'sprite', bgm: 'bgm', sfx: 'sfx' };
            var t = typeMap[k];
            state.assets[k].forEach(function (a) {
                var item = { type: t, id: a.id, src: a.src };
                // 立绘需要带上 character，播放器据此做说话角色高亮/变暗
                if (a.character) item.character = a.character;
                assets.push(item);
            });
        });
        return {
            manifest: {
                name: w.manifest.name,
                author: w.manifest.author,
                version: w.manifest.version,
                created: new Date().toISOString(),
                engine: w.manifest.engine,
                startScene: w.manifest.startScene,
                canvas: w.canvas || w.manifest.canvas
            },
            assets: assets,
            scenes: w.scenes
        };
    }

    function openExport() {
        var data = buildExport();
        var json = JSON.stringify(data, null, 2);
        $('exportArea').value = json;
        $('exportModal').hidden = false;
    }
    function closeExport() { $('exportModal').hidden = true; }

    function downloadJson() {
        var json = $('exportArea').value;
        var name = (state.work.manifest.name || 'asha-work') + '.json';
        var blob = new Blob([json], { type: 'application/json' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url; a.download = name;
        document.body.appendChild(a); a.click();
        document.body.removeChild(a); URL.revokeObjectURL(url);
        toast('已下载 ' + name, 'ok');
    }
    function copyJson() {
        var area = $('exportArea');
        area.select();
        try { document.execCommand('copy'); toast('已复制到剪贴板', 'ok'); }
        catch (e) { navigator.clipboard && navigator.clipboard.writeText(area.value); toast('已复制', 'ok'); }
    }

    function onImportFile(file) {
        var reader = new FileReader();
        reader.onload = function () {
            try {
                var data = JSON.parse(reader.result);
                if (!data.manifest || !Array.isArray(data.scenes)) {
                    throw new Error('格式不合法：缺少 manifest 或 scenes');
                }
                // 校验场景 ID 唯一
                var ids = {}; data.scenes.forEach(function (s) {
                    if (!s.id) throw new Error('存在无 ID 的场景');
                    if (ids[s.id]) throw new Error('场景 ID 重复：' + s.id);
                    ids[s.id] = true;
                    if (!Array.isArray(s.nodes)) s.nodes = [];
                });
                state.work = {
                    manifest: Object.assign(newWork().manifest, data.manifest),
                    scenes: data.scenes
                };
                if (!state.work.manifest.canvas) state.work.manifest.canvas = { width: 1280, height: 720 };
                state.selectedSceneId = data.scenes.length ? data.scenes[0].id : null;
                state.selectedNodeIndex = -1;
                renderAll(); saveDraft();
                toast('导入成功（' + data.scenes.length + ' 场景）', 'ok');
            } catch (e) {
                toast('导入失败：' + e.message, 'err');
            }
        };
        reader.readAsText(file, 'utf-8');
    }

    // ============ 预览 ============
    function preview() {
        var sc = currentScene();
        if (!sc) { toast('请先选中一个场景', 'err'); return; }
        if (!state.work.scenes.length) { toast('暂无场景', 'err'); return; }
        if (!state.work.manifest.startScene ||
            !findScene(state.work.manifest.startScene)) {
            state.work.manifest.startScene = state.work.scenes[0].id;
        }
        var data = buildExport();
        try {
            localStorage.setItem(PREVIEW_KEY, JSON.stringify(data));
            window.open('player.php?mode=preview', '_blank');
        } catch (e) {
            toast('预览失败：' + e.message, 'err');
        }
    }

    // ============ 事件绑定 ============
    function bindEvents() {
        // 顶部
        $('workName').addEventListener('input', function () {
            state.work.manifest.name = $('workName').value; markDirty();
        });
        $('workAuthor').addEventListener('input', function () {
            state.work.manifest.author = $('workAuthor').value; markDirty();
        });
        $('canvasW').addEventListener('input', function () {
            state.work.manifest.canvas.width = parseInt($('canvasW').value, 10) || 1280; markDirty();
        });
        $('canvasH').addEventListener('input', function () {
            state.work.manifest.canvas.height = parseInt($('canvasH').value, 10) || 720; markDirty();
        });
        $('workStartScene').addEventListener('change', function () {
            state.work.manifest.startScene = $('workStartScene').value; markDirty();
        });

        $('btnNewWork').addEventListener('click', newWorkConfirm);
        $('btnImport').addEventListener('click', function () { $('fileImport').click(); });
        $('fileImport').addEventListener('change', function () {
            if (this.files && this.files[0]) onImportFile(this.files[0]);
            this.value = '';
        });
        $('btnExport').addEventListener('click', openExport);
        $('btnPreview').addEventListener('click', preview);
        $('closeModal').addEventListener('click', closeExport);
        $('btnCopyJson').addEventListener('click', copyJson);
        $('btnDownload').addEventListener('click', downloadJson);
        $('exportModal').addEventListener('click', function (e) {
            if (e.target === this) closeExport();
        });

        // 左栏 tabs
        $('assetTabs').addEventListener('click', function (e) {
            var t = e.target.closest('.tab');
            if (!t) return;
            state.activeAssetTab = t.dataset.tab;
            Array.prototype.forEach.call($('assetTabs').children, function (c) {
                c.classList.toggle('is-active', c === t);
            });
            renderAssetGrid();
        });

        // 立绘搜索（按角色名/立绘名过滤）
        $('spriteSearch').addEventListener('input', function () {
            state.spriteKeyword = this.value;
            renderAssetGrid();
        });

        // 场景
        $('btnAddScene').addEventListener('click', addScene);
        $('btnDelScene').addEventListener('click', delScene);
        $('sceneId').addEventListener('change', function () { renameScene(this.value); });
        $('sceneId').addEventListener('blur', function () { renameScene(this.value); });

        // 插入节点
        document.querySelector('.insertbar').addEventListener('click', function (e) {
            var chip = e.target.closest('[data-insert]');
            if (!chip) return;
            addNode(chip.dataset.insert);
        });

        // 失焦自动保存
        window.addEventListener('blur', saveDraft);
        // 离开提示
        window.addEventListener('beforeunload', function (e) {
            if ($('saveState').classList.contains('is-dirty')) {
                e.preventDefault(); e.returnValue = '';
            }
        });
    }

    // ============ 初始化 ============
    function ensureFirstScene() {
        if (!state.work.scenes.length) {
            state.work.scenes.push({ id: 'scene_001', nodes: [] });
            state.work.manifest.startScene = 'scene_001';
        }
        if (!state.selectedSceneId || !findScene(state.selectedSceneId)) {
            state.selectedSceneId = state.work.scenes[0].id;
        }
        // 确保 canvas 字段存在（兼容旧草稿）
        if (!state.work.manifest.canvas) {
            state.work.manifest.canvas = { width: 1280, height: 720, orientation: 'landscape' };
        }
    }

    // ============ 素材防下载（轻量） ============
    // 仅提高普通用户的下载门槛：禁用图片右键菜单与拖拽。
    // 注意：浏览器能渲染的图片必然可被获取，此措施无法阻止有技术手段的用户。
    function guardAssets() {
        document.addEventListener('contextmenu', function (e) {
            if (e.target && e.target.tagName === 'IMG') e.preventDefault();
        });
        document.addEventListener('dragstart', function (e) {
            if (e.target && e.target.tagName === 'IMG') e.preventDefault();
        });
    }

    function init() {
        guardAssets();
        // 加载草稿或新建
        var draft = loadDraft();
        state.work = draft || newWork();
        ensureFirstScene();

        bindEvents();
        // 加载素材后渲染
        loadAssets(function () {
            renderAll();
            saveDraft();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
