/**
 * 播放器引擎
 * 数据来源：localStorage['asha:preview']（preview 模式）
 * 流程：读取配置 → 按 manifest.startScene 进入首个场景 → 逐节点执行 → 遇 goto/choose 跳转 → 终点显示"剧终"
 * 支持：背景/立绘/BGM/音效/对话/选项/变量/跳转、打字机、历史、存档、控制键
 */
(function () {
    'use strict';

    var PREVIEW_KEY = 'asha:preview';
    var SAVE_KEY = 'asha:player:saves';
    var CFG_KEY = 'asha:player:cfg';

    // ============ 状态 ============
    var work = null;            // { manifest, assets[], scenes[] }
    var sceneMap = {};          // id -> scene
    var assetMap = {};          // type|id -> asset
    var variables = {};         // 运行时变量
    var history = [];           // 历史对话
    var curSceneId = null;
    var nodeIndex = -1;
    var typingTimer = null;
    var typingText = '';
    var typingPos = 0;
    var isTyping = false;
    var isWaitingChoice = false;
    var isAuto = false;
    var autoTimer = null;
    var isSkipping = false;
    var bgmEl = null;
    var cfg = { speed: 30, auto: 1000, bgm: 0.6 };

    // ============ 工具 ============
    function $(id) { return document.getElementById(id); }
    function el(t, cls) { var e = document.createElement(t); if (cls) e.className = cls; return e; }
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
        t._timer = setTimeout(function () { t.hidden = true; }, 1800);
    }
    function showErr(msg) {
        var b = $('errBox');
        b.textContent = msg;
        b.hidden = false;
    }

    // ============ 素材 ============
    function resolveAsset(type, id) {
        if (!id) return null;
        var key = type + '|' + id;
        return assetMap[key] || null;
    }
    function assetSrc(asset) {
        return 'assets/' + asset.src;
    }

    // ============ 加载配置 ============
    function loadWork() {
        try {
            var raw = localStorage.getItem(PREVIEW_KEY);
            if (!raw) { showErr('未找到预览配置。\n请先在编辑器中点击"预览"。'); return false; }
            work = JSON.parse(raw);
        } catch (e) { showErr('配置解析失败：' + e.message); return false; }
        if (!work.manifest || !Array.isArray(work.scenes)) {
            showErr('配置格式不合法：缺少 manifest 或 scenes。'); return false;
        }
        if (!work.scenes.length) { showErr('作品中没有任何场景。'); return false; }

        // 构建 sceneMap
        sceneMap = {};
        work.scenes.forEach(function (s) { sceneMap[s.id] = s; });

        // 构建 assetMap
        assetMap = {};
        (work.assets || []).forEach(function (a) {
            assetMap[a.type + '|' + a.id] = a;
        });

        // 默认起始场景
        var start = work.manifest.startScene;
        if (!start || !sceneMap[start]) start = work.scenes[0].id;
        curSceneId = start;

        // 标题
        $('topTitle').textContent = work.manifest.name || '未命名作品';

        return true;
    }

    // ============ 进入场景 ============
    function enterScene(id) {
        if (!id || !sceneMap[id]) {
            // 视为终点
            showEnding();
            return;
        }
        curSceneId = id;
        nodeIndex = -1;
        $('sceneTag').textContent = id;
        nextNode();
    }

    // ============ 推进到下一节点 ============
    function nextNode() {
        var sc = sceneMap[curSceneId];
        if (!sc) { showEnding(); return; }
        nodeIndex++;
        if (nodeIndex >= sc.nodes.length) {
            // 场景结束，默认视为剧终（也可配置 next，但场景级 next 需在 goto 节点显式跳转）
            showEnding();
            return;
        }
        var node = sc.nodes[nodeIndex];
        executeNode(node);
    }

    // ============ 执行节点 ============
    function executeNode(node) {
        // 隐藏选项与对话框
        hideChoices();
        switch (node.type) {
            case 'bg':     applyBg(node); nextNode(); break;
            case 'sprite': applySprite(node); nextNode(); break;
            case 'bgm':    playBgm(node); nextNode(); break;
            case 'sfx':    playSfx(node); nextNode(); break;
            case 'say':    startSay(node); break;
            case 'choose': showChoices(node); break;
            case 'var':    applyVar(node); nextNode(); break;
            case 'goto':   enterScene(node.next); break;
            default:       nextNode();
        }
    }

    // ============ 节点处理器 ============
    function applyBg(node) {
        var a = resolveAsset('bg', node.ref);
        var bg = $('bgLayer');
        if (!a) return;
        var src = assetSrc(a);
        if (node.transition === 'none') {
            bg.style.backgroundImage = 'url("' + src + '")';
        } else {
            bg.classList.add('is-fading');
            setTimeout(function () {
                bg.style.backgroundImage = 'url("' + src + '")';
                setTimeout(function () { bg.classList.remove('is-fading'); }, 50);
            }, 300);
        }
    }

    function applySprite(node) {
        var a = resolveAsset('sprite', node.ref);
        var layer = $('spriteLayer');
        if (!a) return;
        var pos = node.position || 'center';
        var id = 'spr_' + a.id;
        var existing = document.getElementById(id);
        if (existing) existing.remove();
        var div = el('div', 'sprite pos-' + pos);
        div.id = id;
        div.dataset.character = a.character || '';
        var img = new Image();
        img.src = assetSrc(a);
        img.alt = a.name || '';
        img.draggable = false;
        div.appendChild(img);
        layer.appendChild(div);
        // 新立绘需按当前说话角色立即应用高亮/变暗状态
        updateSpeakerHighlight(curSpeakers);
        // 触发进入动画
        requestAnimationFrame(function () {
            div.classList.add('is-in');
        });
        if (node.animation === 'none') {
            div.style.transition = 'none';
            div.classList.add('is-in');
            div.style.transition = '';
        }
    }

    function playBgm(node) {
        var a = resolveAsset('bgm', node.ref);
        if (!a) return;
        if (!bgmEl) { bgmEl = new Audio(); bgmEl.loop = true; }
        bgmEl.src = assetSrc(a);
        bgmEl.loop = !!node.loop;
        bgmEl.volume = cfg.bgm;
        var p = bgmEl.play();
        if (p && p.catch) p.catch(function () { /* 自动播放可能被拦截 */ });
    }

    function playSfx(node) {
        var a = resolveAsset('sfx', node.ref);
        if (!a) return;
        var sfx = new Audio(assetSrc(a));
        sfx.volume = cfg.bgm;
        sfx.play().catch(function () {});
    }

    // 对话：打字机
    // 解析 say 节点的 speakers 字段（空格分隔的 character 值）
    // 命中的立绘保持高亮，其他立绘变暗；speakers 为空则所有立绘恢复正常
    var curSpeakers = '';
    function updateSpeakerHighlight(speakers) {
        curSpeakers = speakers || '';
        var set = {};
        if (curSpeakers && typeof curSpeakers === 'string') {
            curSpeakers.trim().split(/\s+/).forEach(function (s) {
                if (s) set[s] = true;
            });
        }
        var sprites = $('spriteLayer').querySelectorAll('.sprite');
        var hasAny = Object.keys(set).length > 0;
        Array.prototype.forEach.call(sprites, function (spr) {
            var ch = spr.dataset.character || '';
            var isSpeaker = hasAny && set[ch];
            spr.classList.toggle('is-speaker', isSpeaker);
            spr.classList.toggle('is-dim', hasAny && !isSpeaker);
        });
    }
    function startSay(node) {
        var dialog = $('dialog');
        dialog.hidden = false;
        $('dialogSpeaker').textContent = node.speaker || '';
        updateSpeakerHighlight(node.speakers);
        typingText = node.text || '';
        typingPos = 0;
        isTyping = true;
        $('dialogText').innerHTML = '<span class="caret"></span>';
        $('clickHint').hidden = true;

        // 历史
        history.push({ speaker: node.speaker || '', text: node.text || '' });

        clearTimeout(typingTimer);
        typeStep();
    }
    function typeStep() {
        if (!isTyping) return;
        if (typingPos >= typingText.length) {
            isTyping = false;
            $('dialogText').textContent = typingText;
            $('clickHint').hidden = false;
            if (isAuto) { autoTimer = setTimeout(advance, cfg.auto); }
            return;
        }
        typingPos++;
        $('dialogText').innerHTML = escapeHtml(typingText.slice(0, typingPos)) + '<span class="caret"></span>';
        var speed = cfg.speed; // 字符/秒
        var delay = Math.max(8, Math.floor(1000 / speed));
        if (isSkipping) delay = 1;
        typingTimer = setTimeout(typeStep, delay);
    }
    function finishTyping() {
        if (!isTyping) return false;
        isTyping = false;
        clearTimeout(typingTimer);
        $('dialogText').textContent = typingText;
        $('clickHint').hidden = false;
        return true;
    }

    // 推进（点击/按键）
    function advance() {
        if (isWaitingChoice) return; // 选项等待中不推进
        if (isTyping) {
            // 跳过打字机
            finishTyping();
            if (isAuto) { clearTimeout(autoTimer); autoTimer = setTimeout(advance, cfg.auto); }
            return;
        }
        // 当前是对话节点 → 进入下一节点
        var sc = sceneMap[curSceneId];
        if (!sc) return;
        if (nodeIndex >= 0 && nodeIndex < sc.nodes.length &&
            sc.nodes[nodeIndex].type === 'say') {
            nextNode();
        }
    }

    // ============ 选项 ============
    function showChoices(node) {
        isWaitingChoice = true;
        var box = $('choices');
        box.innerHTML = '';
        box.hidden = false;
        (node.options || []).forEach(function (opt, i) {
            var b = el('button', 'choice');
            b.textContent = opt.text || ('选项 ' + (i + 1));
            b.addEventListener('click', function () { chooseOption(opt); });
            box.appendChild(b);
        });
        if (!node.options || !node.options.length) {
            // 空选项视为结束
            isWaitingChoice = false;
            showEnding();
        }
    }
    function hideChoices() {
        isWaitingChoice = false;
        $('choices').hidden = true;
        $('choices').innerHTML = '';
    }
    function chooseOption(opt) {
        isWaitingChoice = false;
        hideChoices();
        if (opt.next && sceneMap[opt.next]) {
            enterScene(opt.next);
        } else {
            // 无 next 视为剧终
            showEnding();
        }
    }

    // ============ 变量 ============
    function applyVar(node) {
        if (!node.set) return;
        Object.keys(node.set).forEach(function (k) {
            var v = node.set[k];
            // 支持 +1 / -1 / 数字 / 字符串
            var m = /^([+-]?)(\d+)$/.exec(v);
            if (m) {
                var num = parseInt(m[2], 10);
                if (m[1] === '+' || m[1] === '-') {
                    var cur = parseInt(variables[k], 10) || 0;
                    variables[k] = String(cur + (m[1] === '-' ? -num : num));
                } else {
                    variables[k] = String(num);
                }
            } else {
                variables[k] = v;
            }
        });
    }

    // ============ 剧终 ============
    function showEnding() {
        $('dialog').hidden = true;
        hideChoices();
        $('ending').hidden = false;
        if (bgmEl) { bgmEl.pause(); }
    }

    // ============ 控制键 / 按钮 ============
    function isModalOpen() {
        return !$('historyModal').hidden || !$('saveModal').hidden || !$('settingsModal').hidden;
    }
    function closeAllModals() {
        $('historyModal').hidden = true;
        $('saveModal').hidden = true;
        $('settingsModal').hidden = true;
    }

    function onKey(e) {
        if (isModalOpen()) {
            if (e.key === 'Escape') closeAllModals();
            return;
        }
        switch (e.key) {
            case ' ':
            case 'Enter':
            case 'ArrowRight':
                e.preventDefault(); advance(); break;
            case 'Tab':
                e.preventDefault(); skipToChoice(); break;
            case 'a': case 'A': toggleAuto(); break;
            case 'l': case 'L': openHistory(); break;
            case 's': case 'S': openSave(); break;
            case 'o': case 'O': openLoad(); break;
            case 'Escape': openSettings(); break;
        }
        if (e.ctrlKey && !isSkipping) { startSkipping(); }
    }
    function onKeyUp(e) {
        if (e.key === 'Control' && isSkipping) stopSkipping();
    }
    function startSkipping() {
        isSkipping = true;
        $('btnSkip').classList.add('is-active');
        if (isTyping) typeStep();
    }
    function stopSkipping() {
        isSkipping = false;
        $('btnSkip').classList.remove('is-active');
    }
    function skipToChoice() {
        // 快进到下一选项或场景结束
        var guard = 0;
        while (guard++ < 200) {
            var sc = sceneMap[curSceneId];
            if (!sc) { showEnding(); return; }
            if (nodeIndex + 1 >= sc.nodes.length) { showEnding(); return; }
            var next = sc.nodes[nodeIndex + 1];
            if (next.type === 'choose') { advance(); return; }
            nodeIndex++;
            executeNodeQuiet(next);
        }
    }
    // 静默执行（跳过节点但不展开对话/选项）
    function executeNodeQuiet(node) {
        switch (node.type) {
            case 'bg': applyBg(node); break;
            case 'sprite': applySprite(node); break;
            case 'bgm': playBgm(node); break;
            case 'sfx': break;
            case 'say': history.push({ speaker: node.speaker || '', text: node.text || '' }); break;
            case 'var': applyVar(node); break;
            case 'goto': if (node.next && sceneMap[node.next]) { curSceneId = node.next; nodeIndex = -1; } else { showEnding(); }
        }
    }

    function toggleAuto() {
        isAuto = !isAuto;
        $('btnAuto').classList.toggle('is-active', isAuto);
        if (isAuto && !isTyping && !isWaitingChoice) {
            autoTimer = setTimeout(advance, 200);
        } else if (!isAuto) {
            clearTimeout(autoTimer);
        }
    }

    // ============ 历史 ============
    function openHistory() {
        var list = $('historyList');
        list.innerHTML = '';
        if (!history.length) list.appendChild(el('li', '', '暂无对话'));
        history.forEach(function (h) {
            var li = el('li');
            if (h.speaker) {
                li.innerHTML = '<span class="h-speaker">' + escapeHtml(h.speaker) + '</span>' + escapeHtml(h.text);
            } else {
                li.textContent = h.text;
            }
            list.appendChild(li);
        });
        $('historyModal').hidden = false;
    }

    // ============ 存档 ============
    function getSaves() {
        try { return JSON.parse(localStorage.getItem(SAVE_KEY) || '[]'); }
        catch (e) { return []; }
    }
    function setSaves(arr) {
        localStorage.setItem(SAVE_KEY, JSON.stringify(arr));
    }
    function currentSnapshot() {
        return {
            workName: work ? work.manifest.name : '',
            sceneId: curSceneId,
            nodeIndex: nodeIndex,
            variables: Object.assign({}, variables),
            time: new Date().toISOString()
        };
    }
    function renderSaveList(mode) {
        var list = $('saveList');
        list.innerHTML = '';
        var saves = getSaves();
        for (var i = 0; i < 3; i++) {
            var s = saves[i];
            var slot = el('div', 'save-slot' + (s ? '' : ' is-empty'));
            var info = el('div', 'save-slot__info');
            var title = el('div', 'save-slot__title', '存档槽 ' + (i + 1));
            var meta = el('div', 'save-slot__meta', s
                ? (s.workName || '未命名') + ' · ' + s.sceneId + '#' + s.nodeIndex + ' · ' + new Date(s.time).toLocaleString()
                : '空');
            info.appendChild(title); info.appendChild(meta);
            var acts = el('div', 'save-slot__actions');
            if (mode === 'save') {
                var saveBtn = el('button', 'save-slot__btn', '写入');
                saveBtn.addEventListener('click', (function (idx) { return function () {
                    saves[idx] = currentSnapshot(); setSaves(saves); renderSaveList('save'); toast('已写入存档 ' + (idx + 1), 'ok');
                }; })(i));
                acts.appendChild(saveBtn);
                if (s) {
                    var delBtn = el('button', 'save-slot__btn save-slot__btn--del', '删除');
                    delBtn.addEventListener('click', (function (idx) { return function () {
                        saves[idx] = null; setSaves(saves); renderSaveList('save'); toast('已清除存档 ' + (idx + 1));
                    }; })(i));
                    acts.appendChild(delBtn);
                }
            } else {
                if (s) {
                    var loadBtn = el('button', 'save-slot__btn', '读取');
                    loadBtn.addEventListener('click', (function (snap) { return function () {
                        loadSnapshot(snap); $('saveModal').hidden = true;
                    }; })(s));
                    acts.appendChild(loadBtn);
                } else {
                    var empty = el('span', '', '—'); acts.appendChild(empty);
                }
            }
            slot.appendChild(info); slot.appendChild(acts);
            list.appendChild(slot);
        }
    }
    function openSave() { renderSaveList('save'); $('saveModalTitle').textContent = '存档'; $('saveModal').hidden = false; }
    function openLoad() { renderSaveList('load'); $('saveModalTitle').textContent = '读档'; $('saveModal').hidden = false; }
    function loadSnapshot(snap) {
        if (!snap || !snap.sceneId || !sceneMap[snap.sceneId]) { toast('存档无效', 'err'); return; }
        curSceneId = snap.sceneId;
        nodeIndex = snap.nodeIndex - 1; // 进入后会 nodeIndex++ 再执行
        variables = Object.assign({}, snap.variables || {});
        $('ending').hidden = true;
        if (bgmEl) bgmEl.pause();
        // 清空立绘
        $('spriteLayer').innerHTML = '';
        nextNode();
        toast('已读档', 'ok');
    }

    // ============ 设置 ============
    function loadCfg() {
        try { var c = JSON.parse(localStorage.getItem(CFG_KEY) || '{}'); Object.assign(cfg, c); } catch (e) {}
    }
    function saveCfg() { localStorage.setItem(CFG_KEY, JSON.stringify(cfg)); }
    function openSettings() {
        $('cfgSpeed').value = cfg.speed;
        $('cfgSpeedVal').textContent = cfg.speed;
        $('cfgAuto').value = cfg.auto;
        $('cfgAutoVal').textContent = cfg.auto;
        $('cfgBgm').value = cfg.bgm;
        $('cfgBgmVal').textContent = Math.round(cfg.bgm * 100) + '%';
        $('settingsModal').hidden = false;
    }
    function bindSettings() {
        $('cfgSpeed').addEventListener('input', function () {
            cfg.speed = parseInt(this.value, 10); $('cfgSpeedVal').textContent = cfg.speed; saveCfg();
        });
        $('cfgAuto').addEventListener('input', function () {
            cfg.auto = parseInt(this.value, 10); $('cfgAutoVal').textContent = cfg.auto; saveCfg();
        });
        $('cfgBgm').addEventListener('input', function () {
            cfg.bgm = parseFloat(this.value); $('cfgBgmVal').textContent = Math.round(cfg.bgm * 100) + '%';
            if (bgmEl) bgmEl.volume = cfg.bgm; saveCfg();
        });
    }

    // ============ 绑定 ============
    function bind() {
        // 舞台点击推进
        $('stage').addEventListener('click', function (e) {
            if (isModalOpen()) return;
            if (e.target.closest('.choices')) return; // 选项自己处理
            if (e.target.closest('.topinfo')) return;
            advance();
        });
        document.addEventListener('keydown', onKey);
        document.addEventListener('keyup', onKeyUp);

        $('btnAuto').addEventListener('click', toggleAuto);
        $('btnSkip').addEventListener('mousedown', startSkipping);
        $('btnSkip').addEventListener('mouseup', stopSkipping);
        $('btnSkip').addEventListener('mouseleave', stopSkipping);
        $('btnHistory').addEventListener('click', openHistory);
        $('btnSave').addEventListener('click', openSave);
        $('btnLoad').addEventListener('click', openLoad);
        $('btnSettings').addEventListener('click', openSettings);
        $('btnRestart').addEventListener('click', function () {
            variables = {}; history = [];
            $('ending').hidden = true;
            $('spriteLayer').innerHTML = '';
            if (bgmEl) bgmEl.pause();
            enterScene(work.manifest.startScene || work.scenes[0].id);
        });

        // 模态框关闭
        $('closeHistory').addEventListener('click', function () { $('historyModal').hidden = true; });
        $('closeSave').addEventListener('click', function () { $('saveModal').hidden = true; });
        $('closeSettings').addEventListener('click', function () { $('settingsModal').hidden = true; });
        $('btnClearSave').addEventListener('click', function () {
            if (confirm('确定清空全部存档？')) { setSaves([]); renderSaveList('save'); toast('已清空'); }
        });
        bindSettings();
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

    // ============ 启动 ============
    function init() {
        guardAssets();
        loadCfg();
        bindSettings();
        if (!loadWork()) return;
        bind();
        enterScene(curSceneId);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
