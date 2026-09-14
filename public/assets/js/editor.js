/**
 * 编辑器主逻辑
 * 状态：work = { manifest, scenes[{id, nodes[]}] }
 * 渲染：素材库 / 场景列表 / 节点列表 / 节点属性卡片
 * 能力：场景与节点 CRUD、JSON 导入导出、localStorage 自动保存、预览跳转
 */
(function () {
    'use strict';

    // 服务端注入的运行时上下文（baseUrl / csrfToken / user / upload）
    var ctx = window.DRAMATOOL_CTX || {};

    // ============ 常量 ============
    var STORAGE_KEY = 'dramatool:editor:draft';
    var PREVIEW_KEY = 'dramatool:preview';
    var NODE_TYPES = ['bg', 'sprite', 'spriteRemove', 'bgm', 'sfx', 'say', 'choose', 'var', 'goto'];
    // var 节点 if 条件支持的比较运算符（见需求文档 6.3）
    var CONDITION_OPS = ['==', '!=', '>', '>=', '<', '<='];

    // 条件对象转可读文本，如 { var:'affection', op:'>=', value:'5' } → "affection >= 5"
    function formatCondition(c) {
        if (!c) return '';
        return (c.var || '?') + ' ' + (c.op || '==') + ' ' + (c.value === undefined ? '' : c.value);
    }

    // 节点默认值
    function defaultNode(type) {
        switch (type) {
            // effects 为节点级转场特效（需求 4.2.3）：fade 淡入 / flash 闪白 / shake 震动 / none 无
            case 'bg':     return { type: 'bg',     ref: '', transition: 'fade', effects: '' };
            // character 为节点级字段：同一素材可被多个角色复用，各自独立高亮/变暗
            case 'sprite': return { type: 'sprite', ref: '', character: '', position: 'center', animation: 'fadeIn', effects: '' };
            case 'spriteRemove': return { type: 'spriteRemove', character: '', ref: '', effects: '' };
            case 'bgm':    return { type: 'bgm',    ref: '', loop: true };
            case 'sfx':    return { type: 'sfx',    ref: '' };
            // color 为说话文本颜色（需求 6.3 可选字段）
            case 'say':    return { type: 'say',    speaker: '', text: '', voice: '', speed: 30, speakers: '', color: '' };
            case 'choose': return { type: 'choose', options: [{ text: '选项1', next: '' }] };
            case 'var':    return { type: 'var',    set: { affection: '0' }, if: null };
            case 'goto':   return { type: 'goto',   next: '' };
            default:       return { type: type };
        }
    }

    var NODE_LABELS = {
        bg: '背景', sprite: '立绘', spriteRemove: '移除立绘', bgm: 'BGM', sfx: '音效',
        say: '对话', choose: '选项', var: '变量', goto: '跳转'
    };

    // 转场特效枚举（需求 4.2.3）：空字符串表示不启用
    var EFFECTS = ['', 'fade', 'flash', 'shake', 'none'];
    var EFFECT_LABELS = { '': '无', fade: '淡入', flash: '闪白', shake: '震动', none: '无' };

    // ============ 状态 ============
    var state = {
        assets: { backgrounds: [], sprites: [], bgm: [], sfx: [] },
        myAssets: { backgrounds: [], sprites: [], bgm: [], sfx: [] },
        assetSource: 'builtin',   // 素材来源：builtin（内置）/ mine（我的）
        usage: null,              // 我的素材配额用量 {bytes,count,quota_bytes,quota_count}
        licenses: {},             // 版权协议选项 {key: label}
        work: null,
        selectedSceneId: null,
        selectedNodeIndex: -1,
        activeAssetTab: 'bg',
        spriteKeyword: '',
        spriteCategory: '全部',
        spriteCharacter: null,
        view: 'nodes',      // 中栏视图：nodes（节点卡片）/ outline（剧情大纲）
        assetView: 'grid'   // 素材库视图：grid（网格）/ list（列表）
    };

    // 素材拖拽插入：拖拽中的素材与当前插入位置（null 表示追加到末尾）
    var dragAsset = null;
    var dropIndex = null;

    function newWork() {
        return {
            manifest: {
                name: '未命名作品',
                author: '',
                version: '1.0.0',
                engine: 'dramatool@1.0',
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
        state.assetSource = 'builtin';
        cloudId = 0;
        coverPath = '';
        renderCover();
        ensureFirstScene();
        // tabs 高亮复位到"背景"
        Array.prototype.forEach.call($('assetTabs').children, function (c, i) {
            c.classList.toggle('is-active', i === 0);
        });
        syncAssetSourceTabs();
        renderAll();
        saveDraft();
        resetHistory();
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
        // 错误提示（如"素材被作品引用无法删除"）信息较长，延长展示时间
        t._timer = setTimeout(function () { t.hidden = true; }, type === 'err' ? 4000 : 2000);
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

    // ============ 撤销 / 重做 ============
    // 以整份 work 的 JSON 快照入栈，实现简单且不会漏掉任何字段。
    // 快照只含 work（不含选中态），撤销后按 id 尽量还原选中位置。
    var HISTORY_LIMIT = 60;      // 文档要求 ≥ 50 步
    var MERGE_WINDOW = 3000;     // 连续输入合并窗口（ms），覆盖正常打字停顿
    var history = { stack: [], index: -1, mergeKey: null, mergeTime: 0, locked: false };

    function snapshot() { return JSON.stringify(state.work); }

    // 记录一次可撤销的变更。mergeKey 相同且间隔在窗口内时合并为一步，
    // 用于文本输入这类高频操作，避免每敲一个字就占一格历史。
    // 注意：mergeTime 只在「首次入栈」时刷新，合并期间不再刷新，
    // 否则持续输入会让窗口无限延长，把整段编辑都吞成一步。
    function pushHistory(mergeKey) {
        if (history.locked) return;
        var now = Date.now();
        var snap = snapshot();
        if (history.index >= 0 && history.stack[history.index] === snap) return;

        if (mergeKey && history.mergeKey === mergeKey &&
            now - history.mergeTime < MERGE_WINDOW && history.index >= 0) {
            history.stack[history.index] = snap;
            updateHistoryButtons();
            return;
        }
        history.stack = history.stack.slice(0, history.index + 1);
        history.stack.push(snap);
        if (history.stack.length > HISTORY_LIMIT) history.stack.shift();
        history.index = history.stack.length - 1;
        history.mergeKey = mergeKey || null;
        history.mergeTime = now;
        updateHistoryButtons();
    }

    // 变更 + 记录历史 + 渲染 + 存草稿，供各处操作统一调用
    function commit(mergeKey) {
        pushHistory(mergeKey);
        renderAll();
        markDirty();
    }

    function restoreSnapshot(snap) {
        history.locked = true;
        var prevScene = state.selectedSceneId;
        var prevIndex = state.selectedNodeIndex;
        state.work = JSON.parse(snap);
        // 尽量保持当前选中位置，避免撤销后视图跳走
        if (!findScene(prevScene)) {
            state.selectedSceneId = state.work.scenes.length ? state.work.scenes[0].id : null;
            state.selectedNodeIndex = -1;
        } else {
            state.selectedSceneId = prevScene;
            var sc = currentScene();
            state.selectedNodeIndex = (sc && prevIndex < sc.nodes.length) ? prevIndex : -1;
        }
        history.locked = false;
        renderAll();
        // 撤销/重做时强制把作品信息写回输入框：
        // 若用户正聚焦在作品名等输入框上，renderWorkInfo 的焦点守卫会跳过写回，
        // 导致底层数据已回退但界面仍显示旧值。
        renderWorkInfo(true);
        markDirty();
    }

    function undo() {
        if (history.index <= 0) { toast('没有可撤销的操作'); return; }
        history.index--;
        history.mergeKey = null;
        restoreSnapshot(history.stack[history.index]);
        updateHistoryButtons();
        toast('已撤销');
    }
    function redo() {
        if (history.index >= history.stack.length - 1) { toast('没有可重做的操作'); return; }
        history.index++;
        history.mergeKey = null;
        restoreSnapshot(history.stack[history.index]);
        updateHistoryButtons();
        toast('已重做');
    }
    function updateHistoryButtons() {
        var u = $('btnUndo'), r = $('btnRedo');
        if (u) u.disabled = history.index <= 0;
        if (r) r.disabled = history.index >= history.stack.length - 1;
    }
    // 重置历史（新建作品 / 导入后调用），以当前状态作为新的起点
    function resetHistory() {
        history.stack = [snapshot()];
        history.index = 0;
        history.mergeKey = null;
        updateHistoryButtons();
    }

    // ============ 素材库加载 ============
    // 内置素材来自 assets/manifest.json；用户素材来自 /api/assets（需登录）。
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
            loadMyAssets(cb);
        };
        xhr.send();
    }

    // 加载当前用户上传的素材；未登录时静默跳过
    function loadMyAssets(cb) {
        if (!cloudReady()) { cb(); return; }
        fetch(apiUrl('api/assets'), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().catch(function () { return { ok: false }; });
        }).then(function (res) {
            if (res && res.ok) {
                state.myAssets = res.assets || state.myAssets;
                state.usage = res.usage || null;
                state.licenses = res.licenses || {};
            }
            cb();
        }).catch(function () { cb(); });
    }

    // 当前素材来源下的素材列表
    function assetListByTab(tab) {
        var src = state.assetSource === 'mine' ? state.myAssets : state.assets;
        switch (tab) {
            case 'bg':     return src.backgrounds || [];
            case 'sprite': return src.sprites || [];
            case 'bgm':    return src.bgm || [];
            case 'sfx':    return src.sfx || [];
        }
        return [];
    }
    // 素材相对 public 的完整地址：内置素材在 assets/ 下，用户素材在 uploads/ 下
    function assetUrl(item) {
        if (!item || !item.src) return '';
        return /^(assets|uploads)\//.test(item.src) ? item.src : 'assets/' + item.src;
    }
    // 素材缩略图地址：优先用服务端生成的缩略图，缺失时回退原图
    function assetThumbUrl(item) {
        if (!item) return '';
        var t = item.thumb || item.src;
        if (!t) return '';
        return /^(assets|uploads)\//.test(t) ? t : 'assets/' + t;
    }
    function findAsset(tab, id) {
        var list = assetListByTab(tab);
        for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i];
        return null;
    }

    // ============ 立绘构图（缩放/裁剪） ============
    // 按素材 id 记录，同一张立绘在任何节点被引用时都套用同一套参数。
    function spriteTransforms() {
        var m = state.work.manifest;
        if (!m.spriteTransforms) m.spriteTransforms = {};
        return m.spriteTransforms;
    }
    function hasSpriteTransform(assetId) {
        return !!spriteTransforms()[assetId];
    }
    // 生成内联样式：offsetX/offsetY 为相对画布宽高的比例，与画布分辨率无关
    function spriteTransformStyle(assetId) {
        var t = spriteTransforms()[assetId];
        if (!t) return '';
        var s = 'transform: translate(' + (t.offsetX * 100) + '%, ' + (t.offsetY * 100) + '%)';
        if (t.scale !== 1) s += ' scale(' + t.scale + ')';
        return s + ';';
    }

    // ============ 渲染：素材库 ============
    // 立绘采用"角色列表 → 立绘详情"两级导航：
    // 角色数量可能达数百，平铺渲染会创建上千 DOM 节点导致卡顿，
    // 因此第一级只渲染角色卡片（数量级为角色数），进入角色后才渲染其立绘。
    function spriteCategories() {
        var seen = {}, out = [];
        assetListByTab('sprite').forEach(function (a) {
            var c = a.category || '其他';
            if (!seen[c]) { seen[c] = true; out.push(c); }
        });
        return out;
    }

    // 按 类型 → 角色 聚合，返回角色数组
    function buildCharacterList() {
        var map = {}, out = [];
        assetListByTab('sprite').forEach(function (a) {
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
        // 网格 / 列表双视图（需求 4.2.4 第 3 点）
        grid.classList.toggle('asset-grid--list', state.assetView === 'list');
        var isSprite = (state.activeAssetTab === 'sprite');
        $('spriteFilter').hidden = !isSprite;
        renderAssetUsage();

        if (isSprite) {
            renderSpriteFilter();
            renderSpritePanel(grid);
            return;
        }

        var list = assetListByTab(state.activeAssetTab);
        if (!list.length) {
            var emptyText;
            if (state.assetSource === 'mine') {
                emptyText = cloudReady()
                    ? '还没有上传素材，点击右上角「+ 上传」'
                    : '登录后即可上传并使用自己的素材';
            } else {
                emptyText = '此分类暂无素材';
            }
            grid.appendChild(el('p', 'placeholder', emptyText));
            return;
        }
        list.forEach(function (item) {
            grid.appendChild(buildAssetCard(item, state.activeAssetTab === 'bgm' || state.activeAssetTab === 'sfx'));
        });
    }

    // 配额用量提示（仅"我的素材"下显示）
    function renderAssetUsage() {
        var box = $('assetUsage');
        if (!box) return;
        if (state.assetSource !== 'mine' || !state.usage) {
            box.hidden = true;
            return;
        }
        var u = state.usage;
        var text = '已用 ' + formatBytes(u.bytes) + ' / ' + formatBytes(u.quota_bytes) +
                   ' · ' + u.count + ' / ' + u.quota_count + ' 个';
        box.textContent = text;
        box.hidden = false;
        box.classList.toggle('is-full', u.count >= u.quota_count || u.bytes >= u.quota_bytes);
    }

    function formatBytes(bytes) {
        bytes = Number(bytes) || 0;
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + 'MB';
        if (bytes >= 1024) return Math.round(bytes / 1024) + 'KB';
        return bytes + 'B';
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
            img.src = assetThumbUrl(c.items[0]);
            img.alt = c.character;
            img.loading = 'lazy';
            img.decoding = 'async';
            img.draggable = false;
            thumb.appendChild(img);
            card.appendChild(thumb);
            // 悬停预览该角色的首张立绘
            thumb.addEventListener('mouseenter', function () {
                showPreview(assetUrl(c.items[0]), c.character, card);
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

        var g = el('div', 'asset-grid' + (state.assetView === 'list' ? ' asset-grid--list' : ''));
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

    // ============ 音频试听 ============
    // 同一时刻只播放一个音频；再次点击同一素材则停止。
    var audioEl = null;
    var audioCard = null;

    function stopAudioPreview() {
        if (audioEl) { audioEl.pause(); audioEl = null; }
        if (audioCard) { audioCard.classList.remove('is-playing'); audioCard = null; }
    }

    function toggleAudioPreview(item, card) {
        if (audioCard === card) { stopAudioPreview(); return; }
        stopAudioPreview();
        var a = new Audio(assetUrl(item));
        a.volume = 0.8;
        a.addEventListener('ended', stopAudioPreview);
        a.play().catch(function () { toast('无法播放该音频', 'err'); });
        audioEl = a;
        audioCard = card;
        card.classList.add('is-playing');
    }

    // 构建单个素材卡片
    function buildAssetCard(item, isAudio) {
        var card = el('div', 'asset-item' + (isAudio ? ' asset-item--audio' : ''));
        var thumb = el('div', 'asset-item__thumb');
        if (isAudio) {
            thumb.textContent = '♪';
            thumb.title = '点击试听';
            // 点击音符试听/停止，不触发插入节点
            thumb.addEventListener('click', function (e) {
                e.stopPropagation();
                toggleAudioPreview(item, card);
            });
        } else {
            var img = document.createElement('img');
            // 卡片只加载缩略图（原图留给悬停预览），并延迟到进入视口再请求
            img.src = assetThumbUrl(item);
            img.alt = item.name;
            img.loading = 'lazy';
            img.decoding = 'async';
            img.draggable = false;
            thumb.appendChild(img);
            // 悬停预览完整图片
            thumb.addEventListener('mouseenter', function () {
                showPreview(assetUrl(item), item.name, card);
            });
            thumb.addEventListener('mouseleave', hidePreview);
        }
        card.appendChild(thumb);
        var nameEl = el('div', 'asset-item__name', escapeHtml(item.name));
        // 列表视图下名称可能被省略号截断，悬停显示完整名称
        nameEl.title = item.name;
        card.appendChild(nameEl);
        // 元信息：图片显示分辨率，音频显示时长（需求 4.2.4 第 2 点）
        var metaText = assetMetaText(item);
        if (metaText) card.appendChild(el('div', 'asset-item__meta', metaText));
        // 我的素材：显示可见范围与版权协议，并提供管理入口
        if (item.mine) {
            var lic = state.licenses[item.license] || item.license || '';
            var badge = el('div', 'asset-item__badge' + (item.visibility === 1 ? ' is-public' : ''),
                (item.visibility === 1 ? '公开' : '私人') + (lic ? ' · ' + escapeHtml(lic) : ''));
            badge.title = lic;
            card.appendChild(badge);
            var manage = el('button', 'asset-item__adj', '管理');
            manage.type = 'button';
            manage.title = '修改可见范围 / 版权协议，或删除该素材';
            manage.addEventListener('click', function (e) {
                e.stopPropagation();
                openAssetManage(item);
            });
            card.appendChild(manage);
        }
        // 立绘提供构图调整入口：缩放/裁剪结果按素材 id 记住，之后引用自动套用
        if (state.activeAssetTab === 'sprite') {
            var adj = el('button', 'asset-item__adj', '调整');
            adj.type = 'button';
            adj.title = '调整该立绘的缩放与裁剪';
            if (hasSpriteTransform(item.id)) adj.classList.add('is-set');
            adj.addEventListener('click', function (e) {
                e.stopPropagation();
                window.DramatoolSpriteCrop.show(item);
            });
            card.appendChild(adj);
        }
        card.addEventListener('click', function () { onAssetClick(item); });
        // 拖拽插入剧本（需求 4.2.4 第 3 点）：拖到中栏节点列表的插入位置
        card.draggable = true;
        card.addEventListener('dragstart', function (e) {
            dragAsset = { tab: state.activeAssetTab, item: item };
            e.dataTransfer.effectAllowed = 'copy';
            // 部分浏览器要求必须写入数据，拖拽才会真正开始
            try { e.dataTransfer.setData('text/plain', item.id); } catch (err) {}
            card.classList.add('is-dragging');
        });
        card.addEventListener('dragend', function () {
            dragAsset = null;
            card.classList.remove('is-dragging');
            clearDropHint();
        });
        return card;
    }

    // 素材元信息文本：图片 → "1024×576"，音频 → "1:23"
    function assetMetaText(item) {
        if (item.width && item.height) return item.width + '×' + item.height;
        if (item.duration) {
            var total = Math.round(item.duration);
            var m = Math.floor(total / 60), s = total % 60;
            return m + ':' + (s < 10 ? '0' : '') + s;
        }
        return '';
    }

    // 点击素材 → 在当前场景插入对应节点
    // 由素材生成节点：立绘的角色默认取素材自带的 character，
    // 用户可在属性面板改成别的角色，从而支持多个角色复用同一张立绘并各自独立高亮/变暗
    function nodeFromAsset(tab, item) {
        var node = defaultNode(tab);
        node.ref = item.id;
        if (tab === 'sprite') node.character = item.character || '';
        return node;
    }

    // 插入素材节点：index 为 null 时追加到末尾
    function insertAssetNode(tab, item, index) {
        var sc = currentScene();
        if (!sc) { toast('请先创建/选中场景', 'err'); return; }
        var node = nodeFromAsset(tab, item);
        if (index === null || index === undefined || index < 0 || index > sc.nodes.length) {
            index = sc.nodes.length;
        }
        sc.nodes.splice(index, 0, node);
        state.selectedNodeIndex = index;
        try {
            commit();
        } catch (e) {
            console.error('renderAll failed after asset insert', e);
        }
        toast('已插入 ' + (NODE_LABELS[tab] || tab) + ' 节点');
    }

    function onAssetClick(item) {
        insertAssetNode(state.activeAssetTab, item, null);
    }

    // ============ 我的素材：上传 / 管理 / 删除 ============
    var uploadCfg = (ctx.upload || {});

    function openUpload() {
        if (!cloudReady()) {
            toast('请先登录后再上传素材', 'err');
            setTimeout(function () { location.href = apiUrl('login'); }, 800);
            return;
        }
        // 默认上传类型跟随当前素材分类
        $('uploadType').value = state.activeAssetTab;
        $('uploadFile').value = '';
        $('uploadVisibility').value = '0';
        renderLicenseOptions();
        updateUploadHint();
        $('uploadModal').hidden = false;
    }

    function closeUpload() {
        $('uploadModal').hidden = true;
    }

    // 版权协议下拉：选项由后端 config/upload.licenses 注入
    function renderLicenseOptions() {
        var sel = $('uploadLicense');
        sel.innerHTML = '';
        var list = uploadCfg.licenses || {};
        Object.keys(list).forEach(function (k) {
            var o = el('option', '', escapeHtml(list[k]));
            o.value = k;
            sel.appendChild(o);
        });
    }

    // 按当前类型提示允许的格式与单文件大小上限
    function updateUploadHint() {
        var type = $('uploadType').value;
        var exts = (uploadCfg.allowed || {})[type] || [];
        var max = uploadCfg.maxSize ? formatBytes(uploadCfg.maxSize) : '';
        $('uploadHint').textContent = exts.length
            ? '（' + exts.join('/') + '，单个不超过 ' + max + '）'
            : '';
    }

    function doUpload() {
        var fileInput = $('uploadFile');
        var file = fileInput.files && fileInput.files[0];
        if (!file) { toast('请先选择文件', 'err'); return; }

        var type = $('uploadType').value;
        var exts = (uploadCfg.allowed || {})[type] || [];
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        if (exts.indexOf(ext) < 0) {
            toast('不支持的文件格式，仅允许：' + exts.join('、'), 'err');
            return;
        }
        if (uploadCfg.maxSize && file.size > uploadCfg.maxSize) {
            toast('文件超过 ' + formatBytes(uploadCfg.maxSize) + ' 限制', 'err');
            return;
        }

        var fd = new FormData();
        fd.append('file', file);
        fd.append('type', type);
        fd.append('visibility', $('uploadVisibility').value);
        fd.append('license', $('uploadLicense').value);
        fd.append('_token', ctx.csrfToken);

        var btn = $('btnDoUpload');
        btn.disabled = true;
        btn.textContent = '上传中…';

        fetch(apiUrl('api/assets'), {
            method: 'POST',
            headers: {
                'X-CSRF-Token': ctx.csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: fd,
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().catch(function () {
                return { ok: false, message: '服务器返回异常（HTTP ' + res.status + '）' };
            });
        }).then(function (res) {
            btn.disabled = false;
            btn.textContent = '开始上传';
            if (!res.ok) {
                if (res.need_login) {
                    toast('登录已过期，请重新登录', 'err');
                    setTimeout(function () { location.href = apiUrl('login'); }, 800);
                    return;
                }
                toast(res.message || '上传失败', 'err');
                return;
            }
            closeUpload();
            toast(res.message || '上传成功');
            // 切到"我的素材"并刷新列表
            state.assetSource = 'mine';
            syncAssetSourceTabs();
            loadMyAssets(function () {
                state.activeAssetTab = type;
                syncAssetTabs();
                renderAssetGrid();
            });
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = '开始上传';
            toast('网络异常，上传失败', 'err');
        });
    }

    // 素材管理：修改可见范围 / 版权协议 / 删除
    function openAssetManage(item) {
        var lic = state.licenses[item.license] || item.license || '';
        var msg = '素材：' + item.name + '\n' +
                  '当前：' + (item.visibility === 1 ? '公开使用' : '私人使用') +
                  (lic ? ' · ' + lic : '') + '\n\n' +
                  '输入 1 改为「公开使用」，输入 0 改为「私人使用」，输入 d 删除该素材。';
        var ans = (window.prompt(msg, item.visibility === 1 ? '1' : '0') || '').trim().toLowerCase();
        if (ans === '') return;
        if (ans === 'd') { deleteAsset(item); return; }
        if (ans !== '0' && ans !== '1') { toast('请输入 0、1 或 d', 'err'); return; }
        var visibility = ans === '1' ? 1 : 0;
        if (visibility === item.visibility) return;

        cloudPost('api/assets/' + item.assetId + '/meta', {
            visibility: visibility,
            _token: ctx.csrfToken
        }).then(function (res) {
            if (!res.ok) { toast(res.message || '修改失败', 'err'); return; }
            toast('已改为' + (visibility === 1 ? '公开使用' : '私人使用'));
            loadMyAssets(renderAssetGrid);
        }).catch(function () { toast('网络异常，修改失败', 'err'); });
    }

    function deleteAsset(item) {
        if (!window.confirm('确定删除素材「' + item.name + '」吗？此操作不可恢复。')) return;
        cloudPost('api/assets/' + item.assetId + '/delete', { _token: ctx.csrfToken })
            .then(function (res) {
                if (!res.ok) {
                    // 被作品引用时后端返回 409，提示具体作品名
                    toast(res.message || '删除失败', 'err');
                    return;
                }
                toast('素材已删除');
                loadMyAssets(renderAssetGrid);
            }).catch(function () { toast('网络异常，删除失败', 'err'); });
    }

    // 同步素材来源 tab 高亮
    function syncAssetSourceTabs() {
        var box = $('assetSourceTabs');
        if (!box) return;
        Array.prototype.forEach.call(box.children, function (c) {
            c.classList.toggle('is-active', c.dataset.source === state.assetSource);
        });
    }

    // 同步素材分类 tab 高亮
    function syncAssetTabs() {
        var box = $('assetTabs');
        if (!box) return;
        Array.prototype.forEach.call(box.children, function (c) {
            c.classList.toggle('is-active', c.dataset.tab === state.activeAssetTab);
        });
    }

    // ============ 素材拖拽插入（需求 4.2.4 第 3 点） ============
    // 拖到节点卡片上半部 → 插到该节点之前；下半部 → 之后；拖到空白处 → 追加到末尾
    function clearDropHint() {
        dropIndex = null;
        var box = $('nodeList');
        if (!box) return;
        Array.prototype.forEach.call(box.querySelectorAll('.node'), function (n) {
            n.classList.remove('is-drop-before', 'is-drop-after');
        });
        box.classList.remove('is-drop-end');
    }

    function bindNodeDrop() {
        var box = $('nodeList');
        if (!box) return;

        box.addEventListener('dragover', function (e) {
            if (!dragAsset) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
            var card = e.target.closest ? e.target.closest('.node') : null;
            clearDropHint();
            if (card) {
                var r = card.getBoundingClientRect();
                var after = (e.clientY - r.top) > r.height / 2;
                dropIndex = Number(card.dataset.index) + (after ? 1 : 0);
                card.classList.add(after ? 'is-drop-after' : 'is-drop-before');
            } else {
                // 空白区域：追加到末尾
                dropIndex = currentScene() ? currentScene().nodes.length : null;
                box.classList.add('is-drop-end');
            }
        });

        box.addEventListener('dragleave', function (e) {
            if (!dragAsset) return;
            // 仅当真正离开列表容器时才清除提示
            if (!box.contains(e.relatedTarget)) clearDropHint();
        });

        box.addEventListener('drop', function (e) {
            if (!dragAsset) return;
            e.preventDefault();
            var d = dragAsset;
            var idx = dropIndex;
            clearDropHint();
            dragAsset = null;
            insertAssetNode(d.tab, d.item, idx);
        });
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
                var a = findAsset(node.type, node.ref);
                if (a) return escapeHtml(a.name || a.id);
                return node.ref ? escapeHtml(node.ref) : '<未指定素材>';
            case 'spriteRemove':
                return node.character ? escapeHtml(node.character) : '<未指定角色>';
            case 'say':
                return (node.speaker ? escapeHtml(node.speaker) + '：' : '') +
                       (node.text ? escapeHtml(node.text) : '<空对话>');
            case 'choose':
                return node.options.length + ' 个选项';
            case 'var':
                var keys = Object.keys(node.set || {});
                if (keys.length) return escapeHtml(keys.join(', '));
                return node.if ? '条件分支' : '<空变量>';
            case 'goto':
                return node.next ? '→ ' + escapeHtml(node.next) : '<未指定>';
        }
        return '';
    }
    function nodeSub(node) {
        // 转场特效对所有支持它的节点类型统一展示
        var fx = node.effects ? ' · 特效: ' + node.effects : '';
        switch (node.type) {
            case 'sprite':
                return 'pos: ' + (node.position || 'center') +
                    (node.character ? ' · 角色: ' + node.character : '') +
                    (node.animation ? ' · ' + node.animation : '') + fx;
            case 'spriteRemove':
                return (node.ref ? '素材: ' + node.ref : '按角色移除') + fx;
            case 'bg':     return (node.transition ? 'transition: ' + node.transition : '') + fx;
            case 'bgm':    return node.loop ? 'loop' : 'no-loop';
            case 'say':
                var sayParts = [];
                if (node.voice) sayParts.push('voice: ' + node.voice);
                if (node.color) sayParts.push('color: ' + node.color);
                return sayParts.join(' · ');
            case 'choose': return node.options.map(function (o) { return o.text || '?'; }).join(' / ');
            case 'var':
                var parts = Object.keys(node.set || {}).map(function (k) {
                    return k + '=' + node.set[k];
                });
                if (node.if) parts.push('if ' + formatCondition(node.if));
                return parts.join(' · ');
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
        img.src = assetThumbUrl(a);
        img.alt = a.name || '';
        img.loading = 'lazy';
        img.decoding = 'async';
        img.draggable = false;
        // 立绘已调整构图时，缩略图同步反映缩放/裁剪结果
        if (node.type === 'sprite') {
            var st = spriteTransformStyle(a.id);
            if (st) img.style.cssText = st;
        }
        box.appendChild(img);
        // 悬停预览完整图片
        box.addEventListener('mouseenter', function () {
            showPreview(assetUrl(a), a.name, box);
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
            card.dataset.index = String(i);
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

    // ============ 渲染：剧情大纲（见需求文档 4.2.2 第 3 点） ============
    // 以列表形式展示全部场景的前后顺序与分支走向，分支目标可点击跳转。
    function renderOutline() {
        var box = $('outlineList');
        box.innerHTML = '';
        var scenes = state.work.scenes;
        if (!scenes.length) {
            box.appendChild(el('p', 'placeholder', '暂无场景，点击左侧「+ 新建场景」开始创作。'));
            return;
        }
        scenes.forEach(function (sc) {
            box.appendChild(buildOutlineScene(sc));
        });
    }

    function buildOutlineScene(sc) {
        var isStart = state.work.manifest.startScene === sc.id;
        var sec = el('section', 'outline-scene' +
            (sc.id === state.selectedSceneId ? ' is-active' : ''));

        var head = el('div', 'outline-scene__head');
        head.appendChild(el('span', 'outline-scene__id', escapeHtml(sc.id)));
        if (isStart) head.appendChild(el('span', 'outline-scene__badge', '起始'));
        head.appendChild(el('span', 'outline-scene__count', sc.nodes.length + ' 节点'));
        head.addEventListener('click', function () {
            state.selectedSceneId = sc.id;
            state.selectedNodeIndex = -1;
            renderAll();
        });
        sec.appendChild(head);

        var list = el('ol', 'outline-nodes');
        if (!sc.nodes.length) {
            list.appendChild(el('li', 'outline-empty', '（空场景）'));
        }
        sc.nodes.forEach(function (node, i) {
            list.appendChild(buildOutlineNode(sc, node, i));
        });
        sec.appendChild(list);
        return sec;
    }

    function buildOutlineNode(sc, node, i) {
        var li = el('li', 'outline-node outline-node--' + node.type +
            (sc.id === state.selectedSceneId && i === state.selectedNodeIndex ? ' is-selected' : ''));
        li.appendChild(el('span', 'outline-node__type', NODE_LABELS[node.type] || node.type));
        li.appendChild(el('span', 'outline-node__text', nodeTitle(node)));

        // 分支走向：goto / choose / var-if 的目标场景以可点击链接展示
        var targets = el('span', 'outline-node__targets');
        if (node.type === 'goto' && node.next) {
            targets.appendChild(branchLink(node.next));
        } else if (node.type === 'choose') {
            (node.options || []).forEach(function (o) {
                var link = branchLink(o.next);
                link.insertBefore(el('span', 'outline-branch__label', (o.text || '?') + ' → '),
                    link.firstChild);
                targets.appendChild(link);
            });
        } else if (node.type === 'var' && node.if) {
            var link = branchLink(node.if.next);
            link.insertBefore(el('span', 'outline-branch__label', 'if ' + formatCondition(node.if) + ' → '),
                link.firstChild);
            targets.appendChild(link);
        }
        if (targets.children.length) li.appendChild(targets);

        li.addEventListener('click', function () {
            state.selectedSceneId = sc.id;
            state.selectedNodeIndex = i;
            setView('nodes');
        });
        return li;
    }

    // 生成指向目标场景的分支链接；目标不存在时标记为断链
    function branchLink(sceneId) {
        var exists = !!findScene(sceneId);
        var a = el('span', 'outline-branch' + (exists ? '' : ' outline-branch--broken'),
            escapeHtml(sceneId || '<未指定>'));
        // 阻止冒泡：避免触发所在节点行的点击（切回节点视图）
        a.addEventListener('click', function (e) { e.stopPropagation(); });
        if (exists) {
            a.title = '跳转到场景 ' + sceneId;
            a.addEventListener('click', function () {
                state.selectedSceneId = sceneId;
                state.selectedNodeIndex = -1;
                renderAll();
            });
        } else {
            a.title = '目标场景不存在';
        }
        return a;
    }

    // 切换中栏视图（节点卡片 / 剧情大纲）
    function setView(view) {
        state.view = view;
        Array.prototype.forEach.call($('viewTabs').children, function (b) {
            b.classList.toggle('is-active', b.dataset.view === view);
        });
        renderCenter();
    }

    // 按当前视图渲染中栏主体
    function renderCenter() {
        var isOutline = state.view === 'outline';
        $('nodeList').hidden = isOutline;
        $('outlineList').hidden = !isOutline;
        $('emptyHint').hidden = isOutline;
        $('viewHint').textContent = isOutline ? '点击节点可回到节点视图编辑' : '';
        if (isOutline) {
            renderOutline();
        } else {
            renderNodeList();
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
            case 'spriteRemove': spriteRemoveFields(wrap, node); break;
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

    // 转场特效下拉（需求 4.2.3）：作用于 bg / sprite / spriteRemove 节点
    function effectsSelect(value) {
        var s = el('select', 'field__input');
        EFFECTS.forEach(function (k) {
            var o = el('option', '', EFFECT_LABELS[k] || k);
            o.value = k;
            if (k === (value || '')) o.selected = true;
            s.appendChild(o);
        });
        return s;
    }

    function bgFields(w, n) {
        var sel = assetSelect('bg', n.ref);
        sel.addEventListener('change', function () { n.ref = sel.value; commit(); });
        w.appendChild(fieldRow('背景素材', '')).appendChild(sel);
        var tr = textInput(n.transition || '', '过渡效果 fade/none');
        tr.addEventListener('input', function () { n.transition = tr.value; commit('bg.transition'); });
        w.appendChild(fieldRow('过渡', '')).appendChild(tr);
        var fx = effectsSelect(n.effects);
        fx.addEventListener('change', function () { n.effects = fx.value; commit(); });
        w.appendChild(fieldRow('转场特效', '')).appendChild(fx);
    }
    function spriteFields(w, n) {
        var sel = assetSelect('sprite', n.ref);
        sel.addEventListener('change', function () {
            n.ref = sel.value;
            // 换素材时若角色为空，自动补上素材自带的 character
            if (!n.character) {
                var na = findAsset('sprite', n.ref);
                if (na && na.character) n.character = na.character;
            }
            commit();
        });
        w.appendChild(fieldRow('立绘素材', '')).appendChild(sel);

        // 角色：节点级字段，决定说话时该立绘高亮还是变暗。
        // 多个角色可复用同一素材，只要角色值不同即可分别控制。
        var ch = textInput(n.character || '', '角色名，需与对话的"说话角色"一致');
        ch.addEventListener('input', function () {
            n.character = ch.value;
            refreshActiveNodeCard(); markDirty(); pushHistory('sprite.character');
        });
        w.appendChild(fieldRow('角色', '')).appendChild(ch);

        // 调整构图：缩放/裁剪，按素材 id 记住，之后引用同一立绘自动套用
        var a = findAsset('sprite', n.ref);
        var adj = el('button', 'btn btn--ghost btn--xs', '调整构图');
        adj.type = 'button';
        adj.disabled = !a;
        if (a && hasSpriteTransform(a.id)) adj.textContent = '调整构图（已修改）';
        adj.addEventListener('click', function () {
            if (!a) { toast('请先选择立绘素材', 'err'); return; }
            window.DramatoolSpriteCrop.show(a);
        });
        w.appendChild(fieldRow('缩放/裁剪', '')).appendChild(adj);

        var pos = selectInput(['left', 'center', 'right'], n.position || 'center');
        pos.addEventListener('change', function () { n.position = pos.value; commit(); });
        w.appendChild(fieldRow('位置', '')).appendChild(pos);
        var ani = selectInput(['fadeIn', 'moveIn', 'zoomIn', 'none'], n.animation || 'fadeIn');
        ani.addEventListener('change', function () { n.animation = ani.value; refreshActiveNodeCard(); markDirty(); pushHistory('sprite.animation'); });
        w.appendChild(fieldRow('动画', '')).appendChild(ani);
        var fx = effectsSelect(n.effects);
        fx.addEventListener('change', function () { n.effects = fx.value; commit(); });
        w.appendChild(fieldRow('转场特效', '')).appendChild(fx);
    }
    // 移除立绘：按角色撤下画面上对应的立绘（同素材多角色时用于单独撤下）
    function spriteRemoveFields(w, n) {
        var ch = textInput(n.character || '', '要移除的角色名；留空则移除该素材的所有立绘');
        ch.addEventListener('input', function () {
            n.character = ch.value;
            refreshActiveNodeCard(); markDirty(); pushHistory('spriteRemove.character');
        });
        w.appendChild(fieldRow('角色', '')).appendChild(ch);

        var sel = assetSelect('sprite', n.ref);
        sel.addEventListener('change', function () { n.ref = sel.value; commit(); });
        w.appendChild(fieldRow('限定素材', '')).appendChild(sel);
        var fx = effectsSelect(n.effects);
        fx.addEventListener('change', function () { n.effects = fx.value; commit(); });
        w.appendChild(fieldRow('转场特效', '')).appendChild(fx);
        w.appendChild(el('p', 'field__hint',
            '角色与素材都留空时不做任何操作。只填角色则移除该角色的立绘；只填素材则移除该素材的所有立绘。'));
    }
    function bgmFields(w, n) {
        var sel = assetSelect('bgm', n.ref);
        sel.addEventListener('change', function () { n.ref = sel.value; commit(); });
        w.appendChild(fieldRow('BGM', '')).appendChild(sel);
        var lp = el('label', 'field field--inline');
        var cb = document.createElement('input');
        cb.type = 'checkbox'; cb.checked = !!n.loop;
        cb.addEventListener('change', function () { n.loop = cb.checked; commit(); });
        lp.appendChild(cb);
        lp.appendChild(el('span', 'field__label', '循环播放'));
        w.appendChild(lp);
    }
    function sfxFields(w, n) {
        var sel = assetSelect('sfx', n.ref);
        sel.addEventListener('change', function () { n.ref = sel.value; commit(); });
        w.appendChild(fieldRow('音效', '')).appendChild(sel);
    }
    function sayFields(w, n) {
        var sp = textInput(n.speaker || '', '角色名');
        // 输入时仅局部更新中栏预览，不重建属性面板，避免每输入一个字就失焦
        sp.addEventListener('input', function () { n.speaker = sp.value; refreshActiveNodeCard(); markDirty(); pushHistory('say.speaker'); });
        w.appendChild(fieldRow('说话人', '')).appendChild(sp);
        var spk = textInput(n.speakers || '', '立绘 character 值，多个用空格分隔；留空表示不变暗任何人');
        spk.addEventListener('input', function () { n.speakers = spk.value; markDirty(); pushHistory('say.speakers'); });
        w.appendChild(fieldRow('说话角色', '')).appendChild(spk);
        var tx = el('textarea', 'field__input');
        tx.rows = 3; tx.value = n.text || '';
        tx.placeholder = '对话文本';
        tx.addEventListener('input', function () { n.text = tx.value; refreshActiveNodeCard(); markDirty(); pushHistory('say.text'); });
        var fr = fieldRow('文本', ''); fr.appendChild(tx); w.appendChild(fr);
        var vc = textInput(n.voice || '', '语音文件（可选）');
        vc.addEventListener('input', function () { n.voice = vc.value; markDirty(); pushHistory('say.voice'); });
        w.appendChild(fieldRow('语音', '')).appendChild(vc);
        // 文本颜色（需求 6.3 可选字段）：留空则用主题默认色
        var cl = textInput(n.color || '', '如 #ff6b6b 或 red；留空用默认色');
        cl.addEventListener('input', function () { n.color = cl.value; markDirty(); pushHistory('say.color'); });
        w.appendChild(fieldRow('文本颜色', '')).appendChild(cl);
        var sp2 = numberInput(n.speed != null ? n.speed : 30, 1, 200, '打字速度（字符/秒）');
        sp2.addEventListener('input', function () { n.speed = parseInt(sp2.value, 10) || 30; markDirty(); pushHistory('say.speed'); });
        w.appendChild(fieldRow('打字速度', '')).appendChild(sp2);
    }
    function chooseFields(w, n) {
        var list = el('div', 'option-list');
        function renderOptions() {
            list.innerHTML = '';
            (n.options || []).forEach(function (opt, idx) {
                var row = el('div', 'option-row');
                var t = textInput(opt.text || '', '选项文字');
                t.addEventListener('input', function () { opt.text = t.value; refreshActiveNodeCard(); markDirty(); pushHistory('choose.text.' + idx); });
                var nx = sceneSelect(opt.next || '', true);
                nx.addEventListener('change', function () { opt.next = nx.value; commit(); });
                var del = el('button', 'option-row__btn', '✕'); del.title = '删除选项';
                del.addEventListener('click', function () {
                    n.options.splice(idx, 1);
                    renderOptions(); commit();
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
            renderOptions(); commit();
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
                del.addEventListener('click', function () { delete n.set[k]; renderKv(); commit(); });
                kIn.addEventListener('input', function () {
                    var nk = kIn.value;
                    if (nk !== k) { var v = n.set[k]; delete n.set[k]; n.set[nk] = v; k = nk; markDirty(); pushHistory('var.key'); }
                });
                vIn.addEventListener('input', function () { n.set[k] = vIn.value; markDirty(); pushHistory('var.value.' + k); });
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
            renderKv(); commit();
        });
        w.appendChild(add);

        // ---- if 条件分支：条件成立时跳转到指定场景，否则继续下一节点 ----
        var condBox = el('div', 'cond-box');
        w.appendChild(condBox);
        function renderCond() {
            condBox.innerHTML = '';
            var head = el('div', 'cond-box__head');
            head.appendChild(el('span', 'cond-box__title', '条件分支（if）'));
            var toggle = el('button', 'btn btn--ghost btn--xs', n.if ? '移除条件' : '+ 添加条件');
            toggle.addEventListener('click', function () {
                n.if = n.if ? null : { var: '', op: '==', value: '', next: '' };
                commit();
            });
            head.appendChild(toggle);
            condBox.appendChild(head);
            if (!n.if) {
                condBox.appendChild(el('p', 'cond-box__hint', '未设置条件：节点仅执行变量赋值。'));
                return;
            }
            var row = el('div', 'cond-row');
            var varIn = textInput(n.if['var'] || '', '变量名');
            varIn.addEventListener('input', function () { n.if['var'] = varIn.value; markDirty(); pushHistory('if.var'); });
            var opSel = document.createElement('select');
            opSel.className = 'field__input field__input--sm';
            CONDITION_OPS.forEach(function (op) {
                var o = document.createElement('option');
                o.value = op; o.textContent = op;
                if ((n.if.op || '==') === op) o.selected = true;
                opSel.appendChild(o);
            });
            opSel.addEventListener('change', function () { n.if.op = opSel.value; commit(); });
            var valIn = textInput(n.if.value === undefined ? '' : String(n.if.value), '比较值');
            valIn.addEventListener('input', function () { n.if.value = valIn.value; markDirty(); pushHistory('if.value'); });
            row.appendChild(varIn); row.appendChild(opSel); row.appendChild(valIn);
            condBox.appendChild(row);

            var nextSel = sceneSelect(n.if.next || '', true);
            nextSel.addEventListener('change', function () { n.if.next = nextSel.value; commit(); });
            condBox.appendChild(fieldRow('条件成立时跳转到', '')).appendChild(nextSel);
            condBox.appendChild(el('p', 'cond-box__hint', '条件不成立时继续执行下一个节点。'));
        }
        renderCond();
    }
    function gotoFields(w, n) {
        var sel = sceneSelect(n.next || '', true);
        sel.addEventListener('change', function () { n.next = sel.value; commit(); });
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
    function renderWorkInfo(force) {
        var m = state.work.manifest;
        if (!m.canvas) m.canvas = { width: 1280, height: 720, orientation: 'landscape' };
        // 正常渲染时跳过聚焦中的输入框，避免打断用户输入；
        // 撤销/重做等需要强制同步的场景传 force=true 覆盖。
        if (force || document.activeElement !== $('workName')) $('workName').value = m.name || '';
        if (force || document.activeElement !== $('workAuthor')) $('workAuthor').value = m.author || '';
        if (force || document.activeElement !== $('canvasW')) $('canvasW').value = m.canvas.width;
        if (force || document.activeElement !== $('canvasH')) $('canvasH').value = m.canvas.height;
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
        renderCenter();
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
        commit();
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
        commit();
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
        commit();
    }

    // ============ 操作：节点 CRUD ============
    function addNode(type) {
        var sc = currentScene();
        if (!sc) { toast('请先创建/选中场景', 'err'); return; }
        sc.nodes.push(defaultNode(type));
        state.selectedNodeIndex = sc.nodes.length - 1;
        commit();
        toast('已插入 ' + (NODE_LABELS[type] || type) + ' 节点');
    }
    function delNode(i) {
        var sc = currentScene();
        if (!sc || i < 0 || i >= sc.nodes.length) return;
        sc.nodes.splice(i, 1);
        if (state.selectedNodeIndex >= sc.nodes.length) state.selectedNodeIndex = sc.nodes.length - 1;
        commit();
    }
    function moveNode(i, dir) {
        var sc = currentScene();
        if (!sc) return;
        var j = i + dir;
        if (j < 0 || j >= sc.nodes.length) return;
        var tmp = sc.nodes[i]; sc.nodes[i] = sc.nodes[j]; sc.nodes[j] = tmp;
        if (state.selectedNodeIndex === i) state.selectedNodeIndex = j;
        else if (state.selectedNodeIndex === j) state.selectedNodeIndex = i;
        commit();
    }

    // ============ TXT 脚本格式（参考 WebGAL，见需求文档 6.4） ============
    // 语法：
    //   #meta key=value        作品信息（name/author/version/engine/startScene/canvas）
    //   #scene <id>            场景开始
    //   bg:<ref>[:<transition>[:<effects>]];
    //   sprite:<ref>[:<character>[:<position>[:<animation>[:<effects>]]]];
    //   spriteRemove:<character>:<ref>[:<effects>];   按角色/素材移除立绘
    //   bgm:<ref>[:loop|noloop];
    //   sfx:<ref>;
    //   say:<speaker>:<text>[:<voice>[:<speed>[:<speakers>[:<color>]]]];
    //   choose:<text>:<next> | <text>:<next>;
    //   var:<key>=<value>[,<key>=<value>];
    //   goto:<sceneId>;
    // 说明：以 ; 结尾，字段用 : 分隔，| 分隔选项，, 分隔变量。
    // 文本中的特殊字符用反斜杠转义（\: \; \| \, \\ \n）。

    var TXT_ESCAPE = { '\\': '\\\\', ':': '\\:', ';': '\\;', '|': '\\|', ',': '\\,', '\n': '\\n', '\r': '' };

    function txtEsc(s) {
        return String(s == null ? '' : s).replace(/[\\:;|,\n\r]/g, function (c) {
            return TXT_ESCAPE[c] != null ? TXT_ESCAPE[c] : c;
        });
    }
    // 还原转义字符（用于 #meta 这类不按分隔符切分的整段值）
    function txtUnesc(s) {
        return String(s == null ? '' : s).replace(/\\(.)/g, function (_, c) {
            return c === 'n' ? '\n' : c;
        });
    }
    // 按分隔符切分，但忽略被反斜杠转义的字符
    function txtSplit(line, sep) {
        var out = [], buf = '', esc = false;
        for (var i = 0; i < line.length; i++) {
            var c = line.charAt(i);
            if (esc) {
                buf += (c === 'n' ? '\n' : c);
                esc = false;
            } else if (c === '\\') {
                esc = true;
            } else if (c === sep) {
                out.push(buf); buf = '';
            } else {
                buf += c;
            }
        }
        if (esc) buf += '\\';
        out.push(buf);
        return out;
    }

    function buildTxt() {
        var w = state.work;
        var m = w.manifest;
        var cv = w.canvas || m.canvas || { width: 1280, height: 720 };
        var lines = [];
        lines.push('# Dramatool 剧本脚本（TXT 格式，可读备份）');
        lines.push('# 语法：<类型>:<字段>...;  场景以 #scene <id> 开始');
        lines.push('');
        lines.push('#meta name=' + txtEsc(m.name || ''));
        lines.push('#meta author=' + txtEsc(m.author || ''));
        lines.push('#meta version=' + txtEsc(m.version || '1.0.0'));
        lines.push('#meta engine=' + txtEsc(m.engine || 'dramatool@1.0'));
        lines.push('#meta startScene=' + txtEsc(m.startScene || ''));
        lines.push('#meta canvas=' + (cv.width || 1280) + 'x' + (cv.height || 720));
        lines.push('');

        w.scenes.forEach(function (sc) {
            lines.push('#scene ' + sc.id);
            (sc.nodes || []).forEach(function (n) {
                lines.push(nodeToTxt(n));
            });
            lines.push('');
        });
        return lines.join('\n');
    }

    function nodeToTxt(n) {
        switch (n.type) {
            case 'bg':
                return 'bg:' + txtEsc(n.ref || '') +
                    (n.transition ? ':' + txtEsc(n.transition) : '') +
                    (n.effects ? ':' + txtEsc(n.effects) : '') + ';';
            case 'sprite':
                // sprite:<素材>:<角色>:<位置>:<动画>:<特效>;
                return 'sprite:' + txtEsc(n.ref || '') + ':' + txtEsc(n.character || '') + ':' +
                    txtEsc(n.position || 'center') +
                    (n.animation ? ':' + txtEsc(n.animation) : '') +
                    (n.effects ? ':' + txtEsc(n.effects) : '') + ';';
            case 'spriteRemove':
                // spriteRemove:<角色>:<素材>:<特效>;
                return 'spriteRemove:' + txtEsc(n.character || '') + ':' + txtEsc(n.ref || '') +
                    (n.effects ? ':' + txtEsc(n.effects) : '') + ';';
            case 'bgm':
                return 'bgm:' + txtEsc(n.ref || '') + ':' + (n.loop === false ? 'noloop' : 'loop') + ';';
            case 'sfx':
                return 'sfx:' + txtEsc(n.ref || '') + ';';
            case 'say':
                // say:<speaker>:<text>:<voice>:<speed>:<speakers>:<color>;
                return 'say:' + txtEsc(n.speaker || '') + ':' + txtEsc(n.text || '') + ':' +
                    txtEsc(n.voice || '') + ':' + (n.speed != null ? n.speed : 30) + ':' +
                    txtEsc(n.speakers || '') +
                    (n.color ? ':' + txtEsc(n.color) : '') + ';';
            case 'choose':
                return 'choose:' + (n.options || []).map(function (o) {
                    return txtEsc(o.text || '') + ':' + txtEsc(o.next || '');
                }).join(' | ') + ';';
            case 'var':
                // var:<k=v,...>:<ifVar>:<ifOp>:<ifValue>:<ifNext>;
                return 'var:' + Object.keys(n.set || {}).map(function (k) {
                    return txtEsc(k) + '=' + txtEsc(n.set[k]);
                }).join(',') + ':' +
                    (n.if ? txtEsc(n.if['var'] || '') + ':' + txtEsc(n.if.op || '==') + ':' +
                        txtEsc(n.if.value === undefined ? '' : n.if.value) + ':' +
                        txtEsc(n.if.next || '') : '') + ';';
            case 'goto':
                return 'goto:' + txtEsc(n.next || '') + ';';
            default:
                return '# 未知节点类型：' + n.type;
        }
    }

    // 解析 TXT 脚本为 work 结构。出错时抛出带行号的 Error。
    function parseTxt(text) {
        var manifest = newWork().manifest;
        var scenes = [];
        var cur = null;
        var lines = String(text).replace(/\r\n?/g, '\n').split('\n');

        lines.forEach(function (raw, i) {
            var lineNo = i + 1;
            var line = raw.trim();
            if (!line || line.charAt(0) === '#') {
                // #scene 是有效指令，其余 # 开头视为注释
                if (/^#scene\s+/i.test(line)) {
                    var sid = line.replace(/^#scene\s+/i, '').trim();
                    if (!sid) throw new Error('第 ' + lineNo + ' 行：#scene 缺少场景 ID');
                    if (scenes.some(function (s) { return s.id === sid; })) {
                        throw new Error('第 ' + lineNo + ' 行：场景 ID 重复 ' + sid);
                    }
                    cur = { id: sid, nodes: [] };
                    scenes.push(cur);
                } else if (/^#meta\s+/i.test(line)) {
                    var kv = line.replace(/^#meta\s+/i, '');
                    var eq = kv.indexOf('=');
                    if (eq > 0) {
                        var key = kv.slice(0, eq).trim();
                        var val = kv.slice(eq + 1).trim();
                        if (key === 'canvas') {
                            var mm = /^(\d+)\s*x\s*(\d+)$/i.exec(val);
                            if (mm) manifest.canvas = {
                                width: parseInt(mm[1], 10),
                                height: parseInt(mm[2], 10),
                                orientation: 'landscape'
                            };
                        } else if (key) {
                            manifest[key] = txtUnesc(val);
                        }
                    }
                }
                return;
            }
            if (!cur) throw new Error('第 ' + lineNo + ' 行：节点出现在任何 #scene 之前');

            // 去掉行尾分号
            var body = line.replace(/;\s*$/, '');
            var ci = body.indexOf(':');
            if (ci < 0) throw new Error('第 ' + lineNo + ' 行：缺少 ":" 分隔符');
            var type = body.slice(0, ci).trim().toLowerCase();
            // 节点类型大小写不敏感：统一转小写后映射回内部驼峰命名
            if (type === 'spriteremove') type = 'spriteRemove';
            var rest = body.slice(ci + 1);
            cur.nodes.push(txtToNode(type, rest, lineNo));
        });

        if (!scenes.length) throw new Error('未找到任何 #scene 场景');
        if (!manifest.startScene || !scenes.some(function (s) { return s.id === manifest.startScene; })) {
            manifest.startScene = scenes[0].id;
        }
        return { manifest: manifest, scenes: scenes };
    }

    function txtToNode(type, rest, lineNo) {
        var f = txtSplit(rest, ':');
        switch (type) {
            case 'bg':
                return { type: 'bg', ref: f[0] || '', transition: f[1] || 'fade', effects: f[2] || '' };
            case 'sprite':
                return {
                    type: 'sprite', ref: f[0] || '', character: f[1] || '',
                    position: f[2] || 'center', animation: f[3] || '', effects: f[4] || ''
                };
            case 'spriteRemove':
                return { type: 'spriteRemove', character: f[0] || '', ref: f[1] || '', effects: f[2] || '' };
            case 'bgm':
                return { type: 'bgm', ref: f[0] || '', loop: (f[1] || 'loop') !== 'noloop' };
            case 'sfx':
                return { type: 'sfx', ref: f[0] || '' };
            case 'say':
                var node = {
                    type: 'say', speaker: f[0] || '', text: f[1] || '',
                    voice: f[2] || '', speed: 30, speakers: f[4] || '', color: f[5] || ''
                };
                if (f[3] != null && f[3] !== '') {
                    var sp = parseInt(f[3], 10);
                    if (!isNaN(sp)) node.speed = sp;
                }
                return node;
            case 'choose':
                var opts = txtSplit(rest, '|').map(function (seg) {
                    var p = txtSplit(seg, ':');
                    return { text: (p[0] || '').trim(), next: (p[1] || '').trim() };
                }).filter(function (o) { return o.text || o.next; });
                if (!opts.length) throw new Error('第 ' + lineNo + ' 行：choose 至少需要一个选项');
                return { type: 'choose', options: opts };
            case 'var':
                var set = {};
                txtSplit(f[0] || '', ',').forEach(function (seg) {
                    if (!seg) return;
                    var eq = seg.indexOf('=');
                    if (eq < 0) throw new Error('第 ' + lineNo + ' 行：变量需为 key=value 形式');
                    set[seg.slice(0, eq).trim()] = seg.slice(eq + 1);
                });
                var node = { type: 'var', set: set, if: null };
                // 可选条件段：<ifVar>:<ifOp>:<ifValue>:<ifNext>
                if (f[1] || f[2] || f[3] || f[4]) {
                    var op = (f[2] || '==').trim();
                    if (CONDITION_OPS.indexOf(op) < 0) {
                        throw new Error('第 ' + lineNo + ' 行：不支持的比较运算符 "' + op + '"');
                    }
                    node.if = {
                        'var': (f[1] || '').trim(), op: op,
                        value: f[3] === undefined ? '' : f[3], next: (f[4] || '').trim()
                    };
                }
                return node;
            case 'goto':
                return { type: 'goto', next: f[0] || '' };
            default:
                throw new Error('第 ' + lineNo + ' 行：未知节点类型 "' + type + '"');
        }
    }

    // ============ 配置校验（见需求文档 4.2.5） ============
    // 校验必填字段、素材引用是否存在、next/choices 跳转目标是否存在。
    // 返回 { errors: [], warnings: [] }，errors 非空时导入应被拒绝。
    // 素材引用以「导入文件自带的 assets 段」为准，缺失时回退到平台内置素材库。

    // 颜色合法性：仅允许 #rgb / #rrggbb / #rrggbbaa 或 CSS 颜色关键字。
    // 播放器会把该值写入内联样式，严格白名单可避免样式注入。
    var COLOR_KEYWORDS = ['red', 'orange', 'yellow', 'green', 'cyan', 'blue', 'purple',
        'pink', 'brown', 'black', 'white', 'gray', 'grey', 'gold', 'silver'];
    function isValidColor(v) {
        var s = String(v).trim();
        if (/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(s)) return true;
        return COLOR_KEYWORDS.indexOf(s.toLowerCase()) >= 0;
    }

    function validateWork(data) {
        var errors = [];
        var warnings = [];

        // 1) manifest 必填字段
        var m = data.manifest || {};
        if (!m.name) warnings.push('作品信息：缺少作品名称（name）');
        if (!m.startScene) {
            errors.push('作品信息：缺少起始场景（startScene）');
        } else if (!data.scenes.some(function (s) { return s.id === m.startScene; })) {
            errors.push('作品信息：起始场景 "' + m.startScene + '" 不存在');
        }

        // 2) 素材索引：优先用配置自带的 assets 段，缺失则回退内置素材库
        var assetIndex = {};
        var hasOwnAssets = Array.isArray(data.assets) && data.assets.length > 0;
        if (hasOwnAssets) {
            data.assets.forEach(function (a) {
                if (!a || !a.type || !a.id) return;
                assetIndex[a.type + '|' + a.id] = true;
            });
        } else {
            ['backgrounds', 'sprites', 'bgm', 'sfx'].forEach(function (k) {
                var t = { backgrounds: 'bg', sprites: 'sprite', bgm: 'bgm', sfx: 'sfx' }[k];
                (state.assets[k] || []).forEach(function (a) { assetIndex[t + '|' + a.id] = true; });
            });
        }
        function assetExists(type, id) { return !!assetIndex[type + '|' + id]; }

        // 3) 场景 ID 唯一性
        var sceneIds = {};
        data.scenes.forEach(function (s) {
            if (sceneIds[s.id]) errors.push('场景 "' + s.id + '"：ID 重复');
            sceneIds[s.id] = true;
        });
        function sceneExists(id) { return !!sceneIds[id]; }

        // 4) 逐场景逐节点校验
        data.scenes.forEach(function (sc) {
            var where = '场景 "' + sc.id + '"';
            (sc.nodes || []).forEach(function (n, i) {
                var at = where + ' 第 ' + (i + 1) + ' 个节点（' + (NODE_LABELS[n.type] || n.type) + '）';
                // 转场特效取值校验（bg / sprite / spriteRemove 支持）
                if (n.effects && EFFECTS.indexOf(n.effects) < 0) {
                    errors.push(at + '：不支持的转场特效 "' + n.effects + '"（可选 ' +
                        EFFECTS.filter(function (k) { return k; }).join(' / ') + '）');
                }
                switch (n.type) {
                    case 'bg':
                        if (!n.ref) errors.push(at + '：缺少背景素材（ref）');
                        else if (!assetExists('bg', n.ref)) errors.push(at + '：背景素材 "' + n.ref + '" 不存在');
                        break;
                    case 'sprite':
                        if (!n.ref) errors.push(at + '：缺少立绘素材（ref）');
                        else if (!assetExists('sprite', n.ref)) errors.push(at + '：立绘素材 "' + n.ref + '" 不存在');
                        break;
                    case 'spriteRemove':
                        if (!n.character && !n.ref) errors.push(at + '：需指定角色（character）或素材（ref）');
                        break;
                    case 'bgm':
                        if (!n.ref) errors.push(at + '：缺少 BGM 素材（ref）');
                        else if (!assetExists('bgm', n.ref)) errors.push(at + '：BGM 素材 "' + n.ref + '" 不存在');
                        break;
                    case 'sfx':
                        if (!n.ref) errors.push(at + '：缺少音效素材（ref）');
                        else if (!assetExists('sfx', n.ref)) errors.push(at + '：音效素材 "' + n.ref + '" 不存在');
                        break;
                    case 'say':
                        if (!n.text) errors.push(at + '：缺少对话文本（text）');
                        if (!n.speaker) warnings.push(at + '：未填写说话人（speaker）');
                        // 文本颜色仅接受十六进制或常见 CSS 颜色关键字，避免注入非法样式
                        if (n.color && !isValidColor(n.color)) {
                            errors.push(at + '：文本颜色 "' + n.color + '" 格式不正确（如 #ff6b6b 或 red）');
                        }
                        break;
                    case 'choose':
                        if (!Array.isArray(n.options) || !n.options.length) {
                            errors.push(at + '：至少需要一个选项（options）');
                            break;
                        }
                        n.options.forEach(function (o, oi) {
                            var oat = at + ' 选项 ' + (oi + 1);
                            if (!o.text) errors.push(oat + '：缺少选项文字（text）');
                            if (!o.next) errors.push(oat + '：缺少跳转目标（next）');
                            else if (!sceneExists(o.next)) errors.push(oat + '：跳转目标 "' + o.next + '" 不存在');
                        });
                        break;
                    case 'var':
                        if (!n.set || !Object.keys(n.set).length) {
                            if (!n.if) errors.push(at + '：缺少变量赋值（set）或条件（if）');
                        }
                        if (n.if) {
                            if (!n.if['var']) errors.push(at + ' 条件：缺少变量名（var）');
                            if (CONDITION_OPS.indexOf(n.if.op) < 0) {
                                errors.push(at + ' 条件：不支持的运算符 "' + (n.if.op || '') + '"');
                            }
                            if (n.if.value === undefined || n.if.value === '') {
                                errors.push(at + ' 条件：缺少比较值（value）');
                            }
                            if (!n.if.next) errors.push(at + ' 条件：缺少跳转目标（next）');
                            else if (!sceneExists(n.if.next)) {
                                errors.push(at + ' 条件：跳转目标 "' + n.if.next + '" 不存在');
                            }
                        }
                        break;
                    case 'goto':
                        if (!n.next) errors.push(at + '：缺少跳转目标（next）');
                        else if (!sceneExists(n.next)) errors.push(at + '：跳转目标 "' + n.next + '" 不存在');
                        break;
                    default:
                        errors.push(at + '：未知节点类型 "' + n.type + '"');
                }
            });
        });

        return { errors: errors, warnings: warnings };
    }

    // ============ 导入导出 ============
    function buildExport() {
        var w = state.work;
        // 组装 assets 段（来自 manifest 的素材清单 + 实际被引用的素材）
        var assets = [];
        var seen = {};
        ['backgrounds', 'sprites', 'bgm', 'sfx'].forEach(function (k) {
            var typeMap = { backgrounds: 'bg', sprites: 'sprite', bgm: 'bgm', sfx: 'sfx' };
            var t = typeMap[k];
            state.assets[k].forEach(function (a) {
                assets.push({ type: t, id: a.id, src: a.src });
                seen[a.id] = true;
            });
        });
        // 用户素材：仅导出被剧本实际引用的，避免把整个素材库写进作品
        var used = collectUsedRefs();
        ['backgrounds', 'sprites', 'bgm', 'sfx'].forEach(function (k) {
            var typeMap = { backgrounds: 'bg', sprites: 'sprite', bgm: 'bgm', sfx: 'sfx' };
            var t = typeMap[k];
            (state.myAssets[k] || []).forEach(function (a) {
                if (used[a.id] && !seen[a.id]) {
                    assets.push({ type: t, id: a.id, src: a.src });
                    seen[a.id] = true;
                }
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
                canvas: w.canvas || w.manifest.canvas,
                // 立绘构图（缩放/裁剪），按素材 id 记录，播放器据此还原
                spriteTransforms: w.manifest.spriteTransforms || {}
            },
            assets: assets,
            scenes: w.scenes
        };
    }

    // 收集剧本中所有节点引用到的素材 id
    function collectUsedRefs() {
        var used = {};
        (state.work.scenes || []).forEach(function (sc) {
            (sc.nodes || []).forEach(function (n) {
                if (n.ref) used[n.ref] = true;
            });
        });
        return used;
    }

    // 导出格式：'json' | 'txt'
    var exportFormat = 'json';

    function renderExportArea() {
        if (exportFormat === 'txt') {
            $('exportArea').value = buildTxt();
        } else {
            $('exportArea').value = JSON.stringify(buildExport(), null, 2);
        }
        Array.prototype.forEach.call($('exportTabs').children, function (c) {
            c.classList.toggle('is-active', c.dataset.format === exportFormat);
        });
        $('btnDownload').textContent = '下载 .' + exportFormat;
    }

    function openExport() {
        exportFormat = 'json';
        renderExportArea();
        $('exportModal').hidden = false;
    }
    function closeExport() { $('exportModal').hidden = true; }

    function downloadJson() {
        var content = $('exportArea').value;
        var ext = exportFormat === 'txt' ? '.txt' : '.json';
        var name = (state.work.manifest.name || 'dramatool-work') + ext;
        var mime = exportFormat === 'txt' ? 'text/plain' : 'application/json';
        var blob = new Blob([content], { type: mime + ';charset=utf-8' });
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

    // 导入：按扩展名/内容自动识别 JSON 或 TXT 脚本
    function onImportFile(file) {
        var reader = new FileReader();
        reader.onload = function () {
            var text = String(reader.result);
            var isTxt = /\.txt$/i.test(file.name) ||
                (!/^\s*\{/.test(text) && /^\s*(#|bg:|say:|sprite:|bgm:|sfx:|choose:|var:|goto:)/m.test(text));
            var data;
            try {
                data = isTxt ? parseTxt(text) : parseJsonConfig(text);
            } catch (e) {
                toast('导入失败：' + e.message, 'err');
                return;
            }
            // 校验：必填字段 / 素材引用 / 跳转目标（见需求文档 4.2.5）
            var result = validateWork(data);
            if (result.errors.length) {
                showValidateResult(result, data, isTxt);
                return;
            }
            applyImported(data);
            var msg = '导入成功（' + data.scenes.length + ' 场景，' + (isTxt ? 'TXT' : 'JSON') + '）';
            if (result.warnings.length) msg += '，' + result.warnings.length + ' 条提示';
            toast(msg, 'ok');
        };
        reader.readAsText(file, 'utf-8');
    }

    // 校验未通过：弹出错误清单，用户可选择「仍然导入」（仅警告级问题）或取消
    function showValidateResult(result, data, isTxt) {
        var box = $('validateList');
        box.innerHTML = '';
        result.errors.forEach(function (msg) {
            box.appendChild(el('li', 'validate-item validate-item--err', escapeHtml(msg)));
        });
        result.warnings.forEach(function (msg) {
            box.appendChild(el('li', 'validate-item validate-item--warn', escapeHtml(msg)));
        });
        $('validateSummary').textContent =
            '发现 ' + result.errors.length + ' 个错误、' + result.warnings.length + ' 条提示。' +
            '请修正后重新导入。';
        $('validateModal').hidden = false;
        // 「仍然导入」仅在无错误时可用（有错误说明配置不可播放）
        $('btnForceImport').hidden = result.errors.length > 0;
        $('btnForceImport').onclick = function () {
            $('validateModal').hidden = true;
            applyImported(data);
            toast('已忽略提示导入（' + data.scenes.length + ' 场景，' + (isTxt ? 'TXT' : 'JSON') + '）', 'ok');
        };
    }

    function parseJsonConfig(text) {
        var data = JSON.parse(text);
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
        return data;
    }

    function applyImported(data) {
        state.work = {
            manifest: Object.assign(newWork().manifest, data.manifest),
            scenes: data.scenes
        };
        if (!state.work.manifest.canvas) state.work.manifest.canvas = { width: 1280, height: 720 };
        state.selectedSceneId = data.scenes.length ? data.scenes[0].id : null;
        state.selectedNodeIndex = -1;
        renderAll(); saveDraft();
        resetHistory();
    }

    // ============ 云端保存 / 加载（二期） ============
    // 依赖服务端注入的 window.DRAMATOOL_CTX = { baseUrl, csrfToken, user }
    var cloudId = 0;          // 当前作品在云端的 id，0 表示尚未保存过
    var cloudSaving = false;
    var coverPath = '';       // 当前作品封面相对路径，空串表示未设置
    var coverUploading = false;

    function cloudReady() { return !!(ctx.user && ctx.csrfToken); }

    function apiUrl(path) {
        var base = (ctx.baseUrl || '/').replace(/\/+$/, '');
        return base + '/' + String(path).replace(/^\/+/, '');
    }

    function cloudPost(path, data) {
        var body = new URLSearchParams(data).toString();
        return fetch(apiUrl(path), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-Token': ctx.csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: body,
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().catch(function () {
                return { ok: false, message: '服务器返回异常（HTTP ' + res.status + '）' };
            });
        });
    }

    // 保存到云端：未登录时提示并跳转登录页
    function cloudSave() {
        if (!cloudReady()) {
            toast('请先登录后再保存到云端', 'err');
            setTimeout(function () { location.href = apiUrl('login'); }, 800);
            return;
        }
        if (cloudSaving) return;

        var btn = $('btnCloudSave');
        cloudSaving = true;
        if (btn) { btn.disabled = true; btn.textContent = '保存中…'; }

        cloudPost('api/works/save', {
            id: cloudId,
            title: state.work.manifest.name || '未命名作品',
            description: $('workDesc') ? $('workDesc').value : '',
            tags: $('workTags') ? $('workTags').value : '',
            cover: coverPath,
            data: JSON.stringify(buildExport()),
            _token: ctx.csrfToken
        }).then(function (res) {
            cloudSaving = false;
            if (btn) { btn.disabled = false; btn.textContent = '保存到云端'; }

            if (!res.ok) {
                if (res.need_login) {
                    toast('登录已过期，请重新登录', 'err');
                    setTimeout(function () { location.href = apiUrl('login'); }, 800);
                    return;
                }
                toast(res.message || '保存失败', 'err');
                return;
            }

            cloudId = res.id;
            $('saveState').className = 'topbar__save is-saved';
            $('saveState').textContent = '已保存到云端';
            toast(res.message || '已保存到云端');
        }).catch(function () {
            cloudSaving = false;
            if (btn) { btn.disabled = false; btn.textContent = '保存到云端'; }
            toast('网络异常，保存失败', 'err');
        });
    }

    // 从云端加载指定作品（?work=id）
    function cloudLoad(id) {
        if (!cloudReady()) return;
        fetch(apiUrl('api/works/' + encodeURIComponent(id)), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().catch(function () { return { ok: false, message: '作品数据解析失败' }; });
        }).then(function (res) {
            if (!res.ok || !res.work || !res.work.data) {
                toast(res.message || '作品加载失败', 'err');
                return;
            }
            var data = res.work.data;
            if (!data.manifest || !Array.isArray(data.scenes)) {
                toast('作品数据格式不正确', 'err');
                return;
            }
            cloudId = res.work.id;
            applyImported(data);
            $('workName').value = res.work.title || '';
            if ($('workDesc')) $('workDesc').value = res.work.description || '';
            if ($('workTags')) $('workTags').value = res.work.tags || '';
            coverPath = res.work.cover || '';
            renderCover();
            $('saveState').className = 'topbar__save is-saved';
            $('saveState').textContent = '已从云端加载';
            toast('已加载《' + (res.work.title || '未命名作品') + '》');
        }).catch(function () {
            toast('网络异常，加载失败', 'err');
        });
    }

    // ============ 作品封面（二期） ============
    // 封面单独上传，返回相对路径后随作品保存一并提交
    function coverUrl(path) {
        if (!path) return '';
        return apiUrl('uploads/covers/' + path);
    }

    // 刷新封面预览区
    function renderCover() {
        var preview = $('coverPreview');
        var empty = $('coverEmpty');
        var img = $('coverImg');
        var removeBtn = $('btnCoverRemove');
        if (!preview || !empty) return;

        if (coverPath) {
            img.src = coverUrl(coverPath);
            preview.hidden = false;
            empty.hidden = true;
            if (removeBtn) removeBtn.hidden = false;
        } else {
            img.removeAttribute('src');
            preview.hidden = true;
            empty.hidden = false;
            if (removeBtn) removeBtn.hidden = true;
        }
    }

    // 上传封面文件
    function uploadCover(file) {
        if (!cloudReady()) {
            toast('请先登录后再上传封面', 'err');
            setTimeout(function () { location.href = apiUrl('login'); }, 800);
            return;
        }
        if (coverUploading) return;
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) {
            toast('封面不能超过 2MB', 'err');
            return;
        }

        var picker = document.querySelector('.cover-picker');
        var btn = $('btnCoverPick');
        coverUploading = true;
        if (picker) picker.classList.add('is-uploading');
        if (btn) { btn.disabled = true; btn.textContent = '上传中…'; }

        var form = new FormData();
        form.append('cover', file);
        form.append('_token', ctx.csrfToken);

        fetch(apiUrl('api/works/cover'), {
            method: 'POST',
            headers: {
                'X-CSRF-Token': ctx.csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: form,
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().catch(function () {
                return { ok: false, message: '服务器返回异常（HTTP ' + res.status + '）' };
            });
        }).then(function (res) {
            coverUploading = false;
            if (picker) picker.classList.remove('is-uploading');
            if (btn) { btn.disabled = false; btn.textContent = '选择图片'; }

            if (!res.ok) {
                if (res.need_login) {
                    toast('登录已过期，请重新登录', 'err');
                    setTimeout(function () { location.href = apiUrl('login'); }, 800);
                    return;
                }
                toast(res.message || '封面上传失败', 'err');
                return;
            }
            coverPath = res.cover || '';
            renderCover();
            markDirty();
            toast('封面已上传，保存作品后生效', 'ok');
        }).catch(function () {
            coverUploading = false;
            if (picker) picker.classList.remove('is-uploading');
            if (btn) { btn.disabled = false; btn.textContent = '选择图片'; }
            toast('网络异常，封面上传失败', 'err');
        });
    }

    // 移除封面（仅清空本地引用，保存后生效）
    function removeCover() {
        coverPath = '';
        renderCover();
        markDirty();
        toast('已移除封面，保存作品后生效');
    }

    // ============ 自动保存到云端（二期） ============
    // 仅在已登录且当前作品已存在于云端时启用，避免产生大量无意义的新作品。
    var AUTO_SAVE_INTERVAL = 60000;   // 60s
    var autoSaveTimer = null;
    var autoSaving = false;

    function autoSaveEnabled() {
        return cloudReady() && cloudId > 0 && !cloudSaving && !autoSaving;
    }

    // 静默保存：不改变按钮文案，仅在成功时更新保存状态
    function cloudAutoSave() {
        if (!autoSaveEnabled()) return;
        if (!$('saveState').classList.contains('is-dirty')) return;

        autoSaving = true;
        cloudPost('api/works/save', {
            id: cloudId,
            title: state.work.manifest.name || '未命名作品',
            description: $('workDesc') ? $('workDesc').value : '',
            tags: $('workTags') ? $('workTags').value : '',
            cover: coverPath,
            data: JSON.stringify(buildExport()),
            _token: ctx.csrfToken
        }).then(function (res) {
            autoSaving = false;
            if (!res.ok) return;
            cloudId = res.id;
            $('saveState').className = 'topbar__save is-saved';
            $('saveState').textContent = '已自动保存 ' + (res.saved_at || '').slice(11, 16);
        }).catch(function () {
            autoSaving = false;
        });
    }

    function startAutoSave() {
        if (autoSaveTimer) return;
        autoSaveTimer = setInterval(cloudAutoSave, AUTO_SAVE_INTERVAL);
    }

    // ============ 历史版本（二期） ============
    function openHistory() {
        if (!cloudReady()) {
            toast('请先登录后再查看历史版本', 'err');
            setTimeout(function () { location.href = apiUrl('login'); }, 800);
            return;
        }
        if (cloudId <= 0) {
            toast('请先保存到云端，之后才会有历史版本', 'err');
            return;
        }
        $('historyModal').hidden = false;
        renderHistory();
    }

    function closeHistory() { $('historyModal').hidden = true; }

    function renderHistory() {
        var list = $('historyList');
        list.innerHTML = '<li class="history-empty">加载中…</li>';

        fetch(apiUrl('api/works/' + encodeURIComponent(cloudId) + '/revisions'), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().catch(function () { return { ok: false, message: '历史版本解析失败' }; });
        }).then(function (res) {
            if (!res.ok) {
                list.innerHTML = '<li class="history-empty">' + escapeHtml(res.message || '加载失败') + '</li>';
                return;
            }
            if (!res.revisions || !res.revisions.length) {
                list.innerHTML = '<li class="history-empty">暂无历史版本，保存后会自动生成快照。</li>';
                return;
            }
            list.innerHTML = res.revisions.map(function (r) {
                return '<li class="history-item">' +
                    '<div class="history-item__info">' +
                        '<span class="history-item__time">' + escapeHtml(r.created_at) + '</span>' +
                        '<span class="history-item__remark">' + escapeHtml(r.remark || '快照') + '</span>' +
                    '</div>' +
                    '<button class="btn btn--ghost btn--xs js-restore" type="button" ' +
                        'data-id="' + r.id + '" data-time="' + escapeHtml(r.created_at) + '">恢复</button>' +
                '</li>';
            }).join('');
        }).catch(function () {
            list.innerHTML = '<li class="history-empty">网络异常，加载失败</li>';
        });
    }

    function restoreRevision(btn) {
        var revisionId = btn.dataset.id;
        var time = btn.dataset.time || '';
        if (!window.confirm('确定恢复到 ' + time + ' 的版本吗？当前内容会先自动存为快照。')) return;

        btn.disabled = true;
        cloudPost('api/works/' + encodeURIComponent(cloudId) + '/revisions/' +
                  encodeURIComponent(revisionId) + '/restore', {
            _token: ctx.csrfToken
        }).then(function (res) {
            btn.disabled = false;
            if (!res.ok) { toast(res.message || '恢复失败', 'err'); return; }

            applyImported(res.data);
            $('workName').value = state.work.manifest.name || '';
            $('saveState').className = 'topbar__save is-saved';
            $('saveState').textContent = '已恢复历史版本';
            closeHistory();
            toast(res.message || '已恢复');
        }).catch(function () {
            btn.disabled = false;
            toast('网络异常，恢复失败', 'err');
        });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
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

    // ============ 发布 / 分享（三期） ============
    // 发布前先保存到云端，确保线上内容与编辑器一致；成功后展示短链与嵌入代码。
    function publish() {
        if (!cloudReady()) {
            toast('请先登录后再发布', 'err');
            setTimeout(function () { location.href = apiUrl('login'); }, 800);
            return;
        }
        if (cloudSaving) return;

        var btn = $('btnPublish');
        cloudSaving = true;
        if (btn) { btn.disabled = true; btn.textContent = '发布中…'; }

        cloudPost('api/works/save', {
            id: cloudId,
            title: state.work.manifest.name || '未命名作品',
            description: $('workDesc') ? $('workDesc').value : '',
            tags: $('workTags') ? $('workTags').value : '',
            cover: coverPath,
            data: JSON.stringify(buildExport()),
            _token: ctx.csrfToken
        }).then(function (res) {
            if (!res.ok) {
                cloudSaving = false;
                if (btn) { btn.disabled = false; btn.textContent = '发布'; }
                if (res.need_login) {
                    toast('登录已过期，请重新登录', 'err');
                    setTimeout(function () { location.href = apiUrl('login'); }, 800);
                    return;
                }
                toast(res.message || '发布失败', 'err');
                return;
            }
            cloudId = res.id;
            return cloudPost('api/works/' + encodeURIComponent(cloudId) + '/publish', {
                public: 1,
                _token: ctx.csrfToken
            });
        }).then(function (res) {
            cloudSaving = false;
            if (btn) { btn.disabled = false; btn.textContent = '发布'; }
            if (!res) return;
            if (!res.ok) { toast(res.message || '发布失败', 'err'); return; }

            $('saveState').className = 'topbar__save is-saved';
            $('saveState').textContent = '已发布';
            showShare(res.share_url, res.short_code);
        }).catch(function () {
            cloudSaving = false;
            if (btn) { btn.disabled = false; btn.textContent = '发布'; }
            toast('网络异常，发布失败', 'err');
        });
    }

    // 展示分享面板：短链 + iframe 嵌入代码
    function showShare(shareUrl, shortCode) {
        if (!shareUrl) { toast('已发布', 'ok'); return; }
        var embedUrl = apiUrl('embed/' + shortCode);
        var embedCode = '<iframe src="' + embedUrl + '" width="960" height="540" '
            + 'frameborder="0" allowfullscreen></iframe>';

        $('shareLink').value = shareUrl;
        $('shareEmbed').value = embedCode;
        $('shareModal').hidden = false;
        toast('作品已发布', 'ok');
    }

    function closeShare() { $('shareModal').hidden = true; }

    function copyFrom(inputId, okMsg) {
        var input = $(inputId);
        if (!input) return;
        input.select();
        var done = function () { toast(okMsg, 'ok'); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(done).catch(function () {
                try { document.execCommand('copy'); done(); } catch (e) { toast('复制失败', 'err'); }
            });
            return;
        }
        try { document.execCommand('copy'); done(); } catch (e) { toast('复制失败', 'err'); }
    }

    // ============ 事件绑定 ============
    function bindEvents() {
        // 顶部
        $('workName').addEventListener('input', function () {
            state.work.manifest.name = $('workName').value; markDirty(); pushHistory('work.name');
        });
        $('workAuthor').addEventListener('input', function () {
            state.work.manifest.author = $('workAuthor').value; markDirty(); pushHistory('work.author');
        });
        $('canvasW').addEventListener('input', function () {
            state.work.manifest.canvas.width = parseInt($('canvasW').value, 10) || 1280; markDirty(); pushHistory('work.canvasW');
        });
        $('canvasH').addEventListener('input', function () {
            state.work.manifest.canvas.height = parseInt($('canvasH').value, 10) || 720; markDirty(); pushHistory('work.canvasH');
        });
        $('workStartScene').addEventListener('change', function () {
            state.work.manifest.startScene = $('workStartScene').value; commit();
        });

        $('btnUndo').addEventListener('click', undo);
        $('btnRedo').addEventListener('click', redo);
        // Ctrl+Z 撤销 / Ctrl+Shift+Z 或 Ctrl+Y 重做
        document.addEventListener('keydown', function (e) {
            if (!(e.ctrlKey || e.metaKey)) return;
            var k = e.key.toLowerCase();
            if (k === 'z' && !e.shiftKey) { e.preventDefault(); undo(); }
            else if ((k === 'z' && e.shiftKey) || k === 'y') { e.preventDefault(); redo(); }
        });

        $('btnNewWork').addEventListener('click', newWorkConfirm);
        $('btnImport').addEventListener('click', function () { $('fileImport').click(); });
        $('fileImport').addEventListener('change', function () {
            if (this.files && this.files[0]) onImportFile(this.files[0]);
            this.value = '';
        });
        $('btnExport').addEventListener('click', openExport);
        $('btnCloudSave').addEventListener('click', cloudSave);
        $('btnCoverPick').addEventListener('click', function () { $('coverFile').click(); });
        $('coverFile').addEventListener('change', function () {
            if (this.files && this.files[0]) uploadCover(this.files[0]);
            this.value = '';
        });
        $('btnCoverRemove').addEventListener('click', removeCover);
        $('btnHistory').addEventListener('click', openHistory);
        $('closeHistory').addEventListener('click', closeHistory);
        $('btnCloseHistory').addEventListener('click', closeHistory);
        $('historyModal').addEventListener('click', function (e) {
            if (e.target === this) closeHistory();
        });
        $('historyList').addEventListener('click', function (e) {
            var btn = e.target.closest('.js-restore');
            if (btn) restoreRevision(btn);
        });
        // 导出格式切换（JSON / TXT）
        $('exportTabs').addEventListener('click', function (e) {
            var t = e.target.closest('[data-format]');
            if (!t) return;
            exportFormat = t.dataset.format;
            renderExportArea();
        });
        $('btnPreview').addEventListener('click', preview);
        $('btnPublish').addEventListener('click', publish);
        $('closeShare').addEventListener('click', closeShare);
        $('btnCloseShare').addEventListener('click', closeShare);
        $('btnCopyShare').addEventListener('click', function () { copyFrom('shareLink', '短链已复制'); });
        $('btnCopyEmbed').addEventListener('click', function () { copyFrom('shareEmbed', '嵌入代码已复制'); });
        $('shareModal').addEventListener('click', function (e) {
            if (e.target === this) closeShare();
        });
        $('btnTheme').addEventListener('click', function () {
            if (!window.DramatoolTheme) return;
            window.DramatoolTheme.cycle();
            var names = { dark: '深色', light: '浅色', sepia: '护眼', auto: '跟随系统' };
            toast('主题：' + (names[window.DramatoolTheme.get()] || ''));
        });
        $('closeModal').addEventListener('click', closeExport);
        $('btnCopyJson').addEventListener('click', copyJson);
        $('btnDownload').addEventListener('click', downloadJson);
        $('exportModal').addEventListener('click', function (e) {
            if (e.target === this) closeExport();
        });

        // 导入校验结果弹窗
        $('closeValidate').addEventListener('click', function () { $('validateModal').hidden = true; });
        $('btnCancelImport').addEventListener('click', function () { $('validateModal').hidden = true; });
        $('validateModal').addEventListener('click', function (e) {
            if (e.target === this) $('validateModal').hidden = true;
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

        // 素材来源切换（内置 / 我的）
        $('assetSourceTabs').addEventListener('click', function (e) {
            var t = e.target.closest('.tab');
            if (!t) return;
            // 未登录时"我的素材"不可用，提示登录
            if (t.dataset.source === 'mine' && !cloudReady()) {
                toast('请先登录后再使用我的素材', 'err');
                setTimeout(function () { location.href = apiUrl('login'); }, 800);
                return;
            }
            state.assetSource = t.dataset.source;
            state.spriteCharacter = null;
            syncAssetSourceTabs();
            renderAssetGrid();
        });

        // 上传素材
        $('btnUploadAsset').addEventListener('click', openUpload);
        $('closeUpload').addEventListener('click', closeUpload);
        $('btnCancelUpload').addEventListener('click', closeUpload);
        $('btnDoUpload').addEventListener('click', doUpload);
        $('uploadType').addEventListener('change', updateUploadHint);
        $('uploadModal').addEventListener('click', function (e) {
            if (e.target === this) closeUpload();
        });

        // 素材库视图切换（网格 / 列表）
        $('assetViewTabs').addEventListener('click', function (e) {
            var t = e.target.closest('.tab');
            if (!t) return;
            state.assetView = t.dataset.view;
            Array.prototype.forEach.call($('assetViewTabs').children, function (c) {
                c.classList.toggle('is-active', c === t);
            });
            renderAssetGrid();
        });

        // 中栏视图切换（节点 / 大纲）
        $('viewTabs').addEventListener('click', function (e) {
            var t = e.target.closest('.tab');
            if (!t) return;
            setView(t.dataset.view);
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

        // 失焦自动保存（本地草稿 + 云端同步）
        window.addEventListener('blur', function () {
            saveDraft();
            cloudAutoSave();
        });
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
            // 素材卡片允许拖拽插入剧本，其余图片（含卡片内缩略图）禁止拖拽
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
        bindNodeDrop();
        startAutoSave();
        renderCover();
        // 加载素材后渲染
        loadAssets(function () {
            renderAll();
            saveDraft();
            resetHistory();

            // 带 ?work=id 进入时从云端加载该作品（覆盖本地草稿）
            var m = /[?&]work=(\d+)/.exec(location.search);
            if (m && cloudReady()) {
                cloudLoad(m[1]);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // 暴露给立绘裁剪弹窗（sprite-crop.js）调用
    window.DramatoolEditor = {
        getAsset: function (type, id) { return findAsset(type, id); },
        getTransform: function (assetId) {
            return (state.work.manifest.spriteTransforms || {})[assetId] || null;
        },
        setTransform: function (assetId, tf) {
            var m = state.work.manifest;
            if (!m.spriteTransforms) m.spriteTransforms = {};
            if (tf) m.spriteTransforms[assetId] = tf;
            else delete m.spriteTransforms[assetId];
            commit();
        },
        toast: toast
    };
})();
