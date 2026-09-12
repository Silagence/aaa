/**
 * 立绘裁剪 / 缩放弹窗
 *
 * 用途：原始立绘尺寸往往不适合直接上屏（留白过多、人物偏小或偏移），
 * 这里让用户对单张立绘做缩放与裁剪，并把结果按素材 id 记到作品里，
 * 之后同一张立绘在任何节点被引用时都会自动套用这套参数。
 *
 * 交互：
 *   - 拖拽图片平移
 *   - 滚轮缩放（以光标为中心）
 *   - 拖动四角手柄缩放
 *   - 右侧滑杆精确调整
 *
 * 数据：{ scale, offsetX, offsetY }，offsetX/offsetY 为相对画布宽高的比例，
 * 与画布分辨率无关，因此换画布尺寸后构图不变。
 */
(function () {
    'use strict';

    var MIN_SCALE = 0.1;
    var MAX_SCALE = 8;

    var modal = null;
    var stage = null;
    var imgEl = null;
    var boxEl = null;
    var scaleRange = null;
    var scaleText = null;

    var cur = null;         // 当前编辑的素材 { id, name, src }
    var tf = null;          // 当前变换 { scale, offsetX, offsetY }
    var natural = { w: 0, h: 0 };
    var stageSize = { w: 0, h: 0 };
    var baseScale = 1;      // 图片以 scale=1 铺满画布时的显示比例
    var drag = null;

    function clamp(v, lo, hi) { return v < lo ? lo : (v > hi ? hi : v); }

    // ============ 构建弹窗 ============
    function build() {
        if (modal) return;

        modal = document.createElement('div');
        modal.className = 'modal crop-modal';
        modal.hidden = true;

        var box = document.createElement('div');
        box.className = 'modal__box crop-modal__box';

        var head = document.createElement('div');
        head.className = 'modal__head';
        var h3 = document.createElement('h3');
        h3.textContent = '调整立绘';
        var close = document.createElement('button');
        close.className = 'modal__close';
        close.type = 'button';
        close.textContent = '×';
        close.addEventListener('click', hide);
        head.appendChild(h3);
        head.appendChild(close);

        var body = document.createElement('div');
        body.className = 'modal__body crop-modal__body';

        // 左：画布预览
        stage = document.createElement('div');
        stage.className = 'crop-stage';
        boxEl = document.createElement('div');
        boxEl.className = 'crop-box';
        imgEl = document.createElement('img');
        imgEl.draggable = false;
        imgEl.alt = '';
        boxEl.appendChild(imgEl);
        stage.appendChild(boxEl);
        body.appendChild(stage);

        // 右：控制区
        var side = document.createElement('div');
        side.className = 'crop-side';

        var nameEl = document.createElement('div');
        nameEl.className = 'crop-side__name';
        side.appendChild(nameEl);

        var scaleRow = document.createElement('label');
        scaleRow.className = 'field';
        var scaleLabel = document.createElement('span');
        scaleLabel.className = 'field__label';
        scaleLabel.textContent = '缩放';
        scaleRange = document.createElement('input');
        scaleRange.type = 'range';
        scaleRange.min = '10';
        scaleRange.max = '800';
        scaleRange.step = '1';
        scaleRange.className = 'crop-range';
        scaleText = document.createElement('span');
        scaleText.className = 'crop-side__value';
        scaleRow.appendChild(scaleLabel);
        scaleRow.appendChild(scaleRange);
        scaleRow.appendChild(scaleText);
        side.appendChild(scaleRow);

        var tip = document.createElement('p');
        tip.className = 'crop-side__tip';
        tip.textContent = '拖拽图片平移，滚轮缩放，拖动四角手柄也可缩放。';
        side.appendChild(tip);

        var reset = document.createElement('button');
        reset.className = 'btn btn--ghost btn--sm';
        reset.type = 'button';
        reset.textContent = '恢复默认';
        reset.addEventListener('click', function () {
            tf = { scale: 1, offsetX: 0, offsetY: 0 };
            syncControls();
            apply();
        });
        side.appendChild(reset);

        body.appendChild(side);

        var foot = document.createElement('div');
        foot.className = 'modal__foot';
        var cancel = document.createElement('button');
        cancel.className = 'btn btn--ghost btn--sm';
        cancel.type = 'button';
        cancel.textContent = '取消';
        cancel.addEventListener('click', hide);
        var ok = document.createElement('button');
        ok.className = 'btn btn--primary btn--sm';
        ok.type = 'button';
        ok.textContent = '应用';
        ok.addEventListener('click', commit);
        foot.appendChild(cancel);
        foot.appendChild(ok);

        box.appendChild(head);
        box.appendChild(body);
        box.appendChild(foot);
        modal.appendChild(box);
        document.body.appendChild(modal);

        modal.addEventListener('click', function (e) { if (e.target === modal) hide(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) hide();
        });

        bindStage();
        bindResize();
    }

    // ============ 交互 ============
    function bindStage() {
        stage.addEventListener('pointerdown', function (e) {
            if (e.target.classList.contains('crop-handle')) return;
            e.preventDefault();
            drag = { x: e.clientX, y: e.clientY, ox: tf.offsetX, oy: tf.offsetY };
            stage.setPointerCapture(e.pointerId);
            stage.classList.add('is-dragging');
        });
        stage.addEventListener('pointermove', function (e) {
            if (!drag) return;
            tf.offsetX = drag.ox + (e.clientX - drag.x) / stageSize.w;
            tf.offsetY = drag.oy + (e.clientY - drag.y) / stageSize.h;
            apply();
        });
        function endDrag(e) {
            if (!drag) return;
            drag = null;
            stage.classList.remove('is-dragging');
            if (e && e.pointerId != null && stage.hasPointerCapture(e.pointerId)) {
                stage.releasePointerCapture(e.pointerId);
            }
        }
        stage.addEventListener('pointerup', endDrag);
        stage.addEventListener('pointercancel', endDrag);

        // 滚轮缩放：以光标位置为中心
        stage.addEventListener('wheel', function (e) {
            e.preventDefault();
            var r = stage.getBoundingClientRect();
            var mx = (e.clientX - r.left) / r.width - 0.5;
            var my = (e.clientY - r.top) / r.height - 0.5;
            var old = tf.scale;
            var next = clamp(old * (e.deltaY < 0 ? 1.1 : 1 / 1.1), MIN_SCALE, MAX_SCALE);
            if (next === old) return;
            // 保持光标下的图像点不动
            tf.offsetX = mx - (mx - tf.offsetX) * (next / old);
            tf.offsetY = my - (my - tf.offsetY) * (next / old);
            tf.scale = next;
            syncControls();
            apply();
        }, { passive: false });

        // 四角手柄
        ['nw', 'ne', 'sw', 'se'].forEach(function (dir) {
            var h = document.createElement('div');
            h.className = 'crop-handle crop-handle--' + dir;
            h.addEventListener('pointerdown', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var r = stage.getBoundingClientRect();
                var cx = r.left + r.width / 2;
                var cy = r.top + r.height / 2;
                drag = {
                    handle: true,
                    dir: dir,
                    cx: cx, cy: cy,
                    startDist: Math.max(1, Math.hypot(e.clientX - cx, e.clientY - cy)),
                    startScale: tf.scale
                };
                h.setPointerCapture(e.pointerId);
            });
            h.addEventListener('pointermove', function (e) {
                if (!drag || !drag.handle) return;
                var d = Math.hypot(e.clientX - drag.cx, e.clientY - drag.cy);
                tf.scale = clamp(drag.startScale * (d / drag.startDist), MIN_SCALE, MAX_SCALE);
                syncControls();
                apply();
            });
            function endHandle(e) {
                if (!drag || !drag.handle) return;
                drag = null;
                if (e && e.pointerId != null && h.hasPointerCapture(e.pointerId)) {
                    h.releasePointerCapture(e.pointerId);
                }
            }
            h.addEventListener('pointerup', endHandle);
            h.addEventListener('pointercancel', endHandle);
            stage.appendChild(h);
        });

        scaleRange.addEventListener('input', function () {
            tf.scale = clamp(parseInt(scaleRange.value, 10) / 100, MIN_SCALE, MAX_SCALE);
            syncControls();
            apply();
        });
    }

    var resizeTimer = null;
    function bindResize() {
        window.addEventListener('resize', function () {
            if (!modal || modal.hidden) return;
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () { measure(); apply(); }, 120);
        });
    }

    // ============ 渲染 ============
    function measure() {
        var r = stage.getBoundingClientRect();
        stageSize.w = r.width || 1;
        stageSize.h = r.height || 1;
        // 图片以 scale=1 铺满画布（contain），保证默认构图与播放器一致
        baseScale = natural.w && natural.h
            ? Math.min(stageSize.w / natural.w, stageSize.h / natural.h)
            : 1;
    }

    function apply() {
        var w = natural.w * baseScale * tf.scale;
        var h = natural.h * baseScale * tf.scale;
        imgEl.style.width = w + 'px';
        imgEl.style.height = h + 'px';
        imgEl.style.left = (stageSize.w / 2 + tf.offsetX * stageSize.w - w / 2) + 'px';
        imgEl.style.top = (stageSize.h / 2 + tf.offsetY * stageSize.h - h / 2) + 'px';
    }

    function syncControls() {
        scaleRange.value = String(Math.round(tf.scale * 100));
        scaleText.textContent = Math.round(tf.scale * 100) + '%';
    }

    // ============ 打开 / 关闭 ============
    function show(asset) {
        build();
        cur = asset;
        var saved = window.AshaEditor.getTransform(asset.id);
        tf = saved
            ? { scale: saved.scale, offsetX: saved.offsetX, offsetY: saved.offsetY }
            : { scale: 1, offsetX: 0, offsetY: 0 };

        modal.querySelector('.crop-side__name').textContent = asset.name || asset.id;
        modal.hidden = false;

        imgEl.onload = function () {
            natural.w = imgEl.naturalWidth;
            natural.h = imgEl.naturalHeight;
            measure();
            syncControls();
            apply();
        };
        imgEl.src = 'assets/' + asset.src;
        if (imgEl.complete && imgEl.naturalWidth) imgEl.onload();
    }

    function hide() {
        if (modal) modal.hidden = true;
        drag = null;
    }

    function commit() {
        var isDefault = Math.abs(tf.scale - 1) < 0.001 &&
                        Math.abs(tf.offsetX) < 0.0005 &&
                        Math.abs(tf.offsetY) < 0.0005;
        window.AshaEditor.setTransform(cur.id, isDefault ? null : {
            scale: Math.round(tf.scale * 1000) / 1000,
            offsetX: Math.round(tf.offsetX * 10000) / 10000,
            offsetY: Math.round(tf.offsetY * 10000) / 10000
        });
        hide();
        window.AshaEditor.toast(isDefault ? '已恢复默认构图' : '已应用调整，该立绘将自动套用');
    }

    window.AshaSpriteCrop = { show: show };
})();
